<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\DunningService;
use App\Services\SubscriptionService;
use App\Support\Payments\PaymentProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The customer's own billing screen. Nothing here needs an email to us.
 */
final class BillingController
{
    public function __construct(
        private readonly PaymentProvider $payments,
        private readonly SubscriptionService $subscriptions,
        private readonly DunningService $dunning,
        private readonly TenantContext $tenancy,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $tenant = $this->tenancy->require();
        $profile = $this->profile();
        $subscription = Subscription::query()->with(['plan', 'pendingPlan'])->latest('id')->first();

        $unpaid = Invoice::query()->where('status', Invoice::ISSUED)->latest('period_start')->first();

        return response()->json([
            'data' => [
                'plan' => [
                    'current' => $subscription?->plan->name,
                    'status' => $subscription?->status,
                    'changing_to' => $subscription?->pendingPlan?->name,
                    'changing_on' => $subscription?->pending_plan_starts_on?->toDateString(),
                ],
                'payment_method' => $this->paymentMethod($profile),
                'billing_details' => [
                    'legal_name' => $profile->legal_name,
                    'billing_email' => $profile->billing_email,
                    'tax_id' => $profile->tax_id,
                    'country' => $profile->country,
                ],
                // Only present when something has gone wrong, and then it explains the whole
                // sequence rather than a single alarming line.
                'collection' => $unpaid === null ? null : $this->dunning->scheduleFor($tenant, $unpaid),
                'available_plans' => Plan::query()->where('is_public', true)->orderBy('sort')->get()
                    ->map(fn (Plan $plan) => [
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'unit_price' => number_format($plan->unit_price_minor / 100, 2).' '.$plan->currency,
                        'minimum' => number_format($plan->minimum_charge_minor / 100, 2).' '.$plan->currency,
                        'current' => $plan->getKey() === $subscription?->plan_id,
                    ]),
            ],
        ]);
    }

    public function updateDetails(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $validated = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:190'],
            'billing_email' => ['nullable', 'email', 'max:190'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $this->profile()->update($validated);

        return response()->json(['data' => ['message' => 'Billing details saved.']]);
    }

    /**
     * Card or invoice. Switching to invoicing needs someone to address the invoice to; switching
     * to card needs a card that can actually be charged, so nobody lands in dunning by choosing.
     */
    public function setCollection(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $validated = $request->validate([
            'method' => ['required', 'in:card,invoice'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'billing_email' => ['nullable', 'email', 'max:190'],
        ]);

        $profile = $this->profile();

        if ($validated['method'] === 'invoice') {
            $profile->fill(array_filter([
                'legal_name' => $validated['legal_name'] ?? null,
                'billing_email' => $validated['billing_email'] ?? null,
            ]));

            $missing = array_filter([
                'legal_name' => $profile->legal_name === null ? 'Invoices need the legal name to address them to.' : null,
                'billing_email' => $profile->billing_email === null ? 'Invoices need an email address to be sent to.' : null,
            ]);

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            $profile->forceFill(['prefers_invoicing' => true])->save();

            return response()->json(['data' => [
                'payment_method' => $this->paymentMethod($profile),
                'message' => 'Switched to invoicing. Each invoice is emailed to '.$profile->billing_email
                    .' and paid by bank transfer; nothing is charged automatically.',
            ]]);
        }

        if (! $this->payments->collectsAutomatically()) {
            throw ValidationException::withMessages([
                'method' => 'This account is set up for invoicing only. Contact us to pay by card.',
            ]);
        }

        if (! $profile->hasUsablePaymentMethod()) {
            throw ValidationException::withMessages([
                'method' => 'Add a card first. Nothing has changed: you are still invoiced.',
            ]);
        }

        $profile->forceFill(['prefers_invoicing' => false])->save();

        return response()->json(['data' => [
            'payment_method' => $this->paymentMethod($profile),
            'message' => 'Switched to card. Future invoices are charged to '.$profile->methodSummary().'.',
        ]]);
    }

    public function changePlan(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'acknowledge_losses' => ['sometimes', 'boolean'],
        ]);

        $result = $this->subscriptions->changePlan(
            $this->tenancy->require(),
            Plan::query()->where('code', $validated['plan_code'])->firstOrFail(),
            (bool) ($validated['acknowledge_losses'] ?? false),
        );

        return response()->json([
            'data' => ['effective' => $result['effective'], 'message' => $result['message']],
        ]);
    }

    /**
     * A hosted page where the customer enters card details we never see: the provider's checkout
     * to add a first card, its portal to replace or remove one.
     */
    public function paymentMethodLink(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $validated = $request->validate(['return_url' => ['nullable', 'url', 'max:2048']]);
        $returnUrl = $this->returnUrl($validated['return_url'] ?? null);

        if (! $this->payments->collectsAutomatically()) {
            return response()->json(['data' => [
                'url' => null,
                'message' => 'This account is invoiced rather than charged automatically.',
            ]]);
        }

        $tenant = $this->tenancy->require();
        $profile = $this->profile();

        if ($profile->customer_ref === null) {
            $profile->update(['customer_ref' => $this->payments->syncCustomer($tenant, $profile)]);
        }

        $url = $profile->hasPaymentMethod()
            ? $this->payments->portalUrl($profile, $returnUrl)
            : $this->payments->checkoutUrl($tenant, $profile, $returnUrl);

        return response()->json([
            'data' => [
                'url' => $url,
                'message' => 'Card details are entered on our payment provider\'s page and never reach us.'
                    .($profile->prefers_invoicing ? ' Adding a card does not stop invoicing; switch to card when ready.' : ''),
            ],
        ]);
    }

    /**
     * What the billing screen shows about how this account pays.
     *
     * @return array<string, mixed>
     */
    private function paymentMethod(BillingProfile $profile): array
    {
        $invoiced = $profile->prefers_invoicing || ! $this->payments->collectsAutomatically();

        $card = ! $profile->hasPaymentMethod() ? null : [
            'brand' => $profile->method_brand,
            'last_four' => $profile->method_last_four,
            'exp_month' => $profile->method_exp_month,
            'exp_year' => $profile->method_exp_year,
            'expired' => $profile->methodExpired(),
            'expires_soon' => $profile->methodExpiresSoon(),
        ];

        $summary = match (true) {
            $invoiced => 'Invoiced'.($profile->billing_email !== null ? ' to '.$profile->billing_email : '')
                .' and paid by bank transfer. Nothing is charged automatically.',
            $card === null => 'No card on file. Add one, or switch to invoicing.',
            $card['expired'] => $profile->methodSummary().'. This card has expired: add a new one, or switch to invoicing.',
            $card['expires_soon'] => $profile->methodSummary().'. Charged automatically; replace it before it expires.',
            default => $profile->methodSummary().'. Charged automatically each month.',
        };

        return [
            'collection' => $invoiced ? 'invoice' : 'card',
            'summary' => $summary,
            'card' => $card,
            'card_available' => $this->payments->collectsAutomatically(),
            // Card collection with nothing that can be charged: the next invoice will fail.
            'needs_attention' => ! $invoiced && ! $profile->hasUsablePaymentMethod(),
            // Kept for clients written against the earlier shape.
            'prefers_invoicing' => $profile->prefers_invoicing,
        ];
    }

    private function profile(): BillingProfile
    {
        return BillingProfile::query()->firstOrCreate([], ['provider' => $this->payments->name()]);
    }

    /**
     * Where the provider sends the customer back to. Only ever our own address, so the hosted page
     * cannot be turned into a redirect to somewhere else.
     */
    private function returnUrl(?string $requested): string
    {
        $base = rtrim(config()->string('app.url'), '/');
        $default = $base.'/billing';

        if ($requested === null) {
            return $default;
        }

        $allowed = parse_url($base, PHP_URL_HOST);
        $host = parse_url($requested, PHP_URL_HOST);
        $scheme = parse_url($requested, PHP_URL_SCHEME);

        if ($host === null || $host !== $allowed || ! in_array($scheme, ['https', 'http'], true)) {
            throw ValidationException::withMessages([
                'return_url' => 'The return address must be on '.$allowed.'.',
            ]);
        }

        return $requested;
    }

    public function cancel(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $subscription = $this->subscriptions->cancel($this->tenancy->require(), $validated['reason'] ?? null);

        return response()->json([
            'data' => [
                'message' => sprintf(
                    'Cancelled. Your account works normally until %s, and you can export everything '
                    .'at any time before or after that.',
                    $subscription->current_period_end->toDateString(),
                ),
            ],
        ]);
    }
}
