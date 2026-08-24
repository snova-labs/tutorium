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
        $profile = BillingProfile::query()->firstOrCreate([], ['provider' => $this->payments->name()]);
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
                'payment_method' => [
                    'summary' => $profile->methodSummary(),
                    'prefers_invoicing' => $profile->prefers_invoicing,
                ],
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
            'prefers_invoicing' => ['boolean'],
        ]);

        $profile = BillingProfile::query()->firstOrCreate([], ['provider' => $this->payments->name()]);
        $profile->update($validated);

        return response()->json(['data' => ['message' => 'Billing details saved.']]);
    }

    public function changePlan(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $validated = $request->validate(['plan_code' => ['required', 'string', 'exists:plans,code']]);

        $result = $this->subscriptions->changePlan(
            $this->tenancy->require(),
            Plan::query()->where('code', $validated['plan_code'])->firstOrFail(),
        );

        return response()->json([
            'data' => ['effective' => $result['effective'], 'message' => $result['message']],
        ]);
    }

    /** A hosted page where the customer enters card details we never see. */
    public function paymentMethodLink(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $tenant = $this->tenancy->require();
        $profile = BillingProfile::query()->firstOrCreate([], ['provider' => $this->payments->name()]);

        if ($profile->customer_ref === null) {
            $profile->update(['customer_ref' => $this->payments->syncCustomer($tenant, $profile)]);
        }

        $url = $profile->hasPaymentMethod()
            ? $this->payments->portalUrl($profile, $request->string('return_url')->toString())
            : $this->payments->checkoutUrl($tenant, $profile, $request->string('return_url')->toString());

        return response()->json([
            'data' => [
                'url' => $url,
                'message' => $url === null
                    ? 'This account is invoiced rather than charged automatically.'
                    : 'Card details are entered on our payment provider\'s page and never reach us.',
            ],
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        $subscription = $this->subscriptions->cancel($this->tenancy->require());

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
