<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\Payments\PaymentProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Events from the payment provider.
 *
 * Two rules. Verify the signature before reading anything, because this endpoint is public by
 * necessity. Then record the event and key on the provider's own id, because providers retry and
 * applying a payment twice is a worse failure than applying it late.
 */
final class PaymentWebhookController
{
    public function __construct(
        private readonly PaymentProvider $payments,
        private readonly TenantContext $tenancy,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        if (! $this->payments->verifyWebhook($payload, $signature)) {
            // No detail: an unverified caller learns nothing about why.
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $data = json_decode($payload, true) ?: [];
        $eventId = $data['id'] ?? null;

        if ($eventId === null) {
            return response()->json(['message' => 'Malformed event.'], 422);
        }

        $event = WebhookEvent::query()->firstOrCreate(
            ['provider' => $this->payments->name(), 'event_id' => $eventId],
            ['type' => $data['type'] ?? 'unknown', 'payload' => $data, 'received_at' => now()],
        );

        if ($event->isProcessed()) {
            // Already handled. Acknowledged so the provider stops retrying.
            return response()->json(['message' => 'Already processed.']);
        }

        try {
            $this->dispatch($event);
            $event->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $event->update(['error' => $e->getMessage()]);
            Log::error('Payment webhook failed', ['event' => $eventId, 'error' => $e->getMessage()]);

            // A 500 asks the provider to retry, which is what we want for a transient failure.
            return response()->json(['message' => 'Could not process.'], 500);
        }

        return response()->json(['message' => 'Processed.']);
    }

    private function dispatch(WebhookEvent $event): void
    {
        $object = $event->payload['data']['object'] ?? [];

        match ($event->type) {
            'invoice.paid', 'invoice.payment_succeeded' => $this->invoicePaid($object),
            'invoice.payment_failed' => $this->invoiceFailed($object),
            'setup_intent.succeeded', 'payment_method.attached' => $this->methodAttached($object),
            'customer.subscription.deleted' => $this->subscriptionCancelled($object),
            default => null,
        };
    }

    /** @param array<string, mixed> $object */
    private function invoicePaid(array $object): void
    {
        $this->withTenant($object, function (Invoice $invoice) use ($object): void {
            if ($invoice->status === Invoice::PAID) {
                return;
            }

            $invoice->update([
                'status' => Invoice::PAID,
                'paid_at' => now(),
                'provider_ref' => $object['id'] ?? $invoice->provider_ref,
            ]);

            Subscription::query()->latest('id')->first()?->update(['status' => Subscription::ACTIVE]);
        });
    }

    /** @param array<string, mixed> $object */
    private function invoiceFailed(array $object): void
    {
        // Deliberately not acted on here. Collection is driven by our own schedule so that a
        // customer sees one predictable sequence rather than two systems chasing them.
        $this->withTenant($object, function (Invoice $invoice): void {
            Subscription::query()->latest('id')->first()?->update(['status' => Subscription::PAST_DUE]);
        });
    }

    /** @param array<string, mixed> $object */
    private function methodAttached(array $object): void
    {
        $customerRef = $object['customer'] ?? null;

        if ($customerRef === null) {
            return;
        }

        $profile = $this->tenancy->withoutScoping(
            fn () => BillingProfile::withoutGlobalScopes()->where('customer_ref', $customerRef)->first(),
        );

        if ($profile === null) {
            return;
        }

        $card = $object['card'] ?? [];

        $this->tenancy->withoutScoping(fn () => $profile->update([
            'method_brand' => $card['brand'] ?? null,
            // Only ever the last four and the expiry, so a customer can recognise their own card.
            'method_last_four' => $card['last4'] ?? null,
            'method_exp_month' => $card['exp_month'] ?? null,
            'method_exp_year' => $card['exp_year'] ?? null,
        ]));
    }

    /** @param array<string, mixed> $object */
    private function subscriptionCancelled(array $object): void
    {
        $customerRef = $object['customer'] ?? null;

        $profile = $this->tenancy->withoutScoping(
            fn () => BillingProfile::withoutGlobalScopes()->where('customer_ref', $customerRef)->first(),
        );

        if ($profile === null) {
            return;
        }

        $tenant = $this->tenancy->withoutScoping(fn () => Tenant::query()->find($profile->tenant_id));

        $this->tenancy->runAs($tenant, function (): void {
            Subscription::query()->latest('id')->first()?->update([
                'status' => Subscription::CANCELLED,
                'cancelled_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $object */
    private function withTenant(array $object, callable $callback): void
    {
        $number = $object['metadata']['invoice_number'] ?? null;
        $tenantId = $object['metadata']['tenant_id'] ?? null;

        if ($number === null || $tenantId === null) {
            return;
        }

        $tenant = $this->tenancy->withoutScoping(fn () => Tenant::query()->find((int) $tenantId));

        if ($tenant === null) {
            return;
        }

        $this->tenancy->runAs($tenant, function () use ($number, $callback): void {
            $invoice = Invoice::query()->where('number', $number)->first();

            if ($invoice !== null) {
                $callback($invoice);
            }
        });
    }
}
