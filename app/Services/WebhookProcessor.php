<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handling what a payment provider tells us.
 *
 * Stored first, acted on second, and never acted on twice. Providers retry webhooks for days;
 * without the id check, one retry could mark an invoice paid a second time or reinstate an account
 * that was deliberately cancelled.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly InvoiceComposer $invoices,
    ) {}

    /** @param array<string, mixed> $event */
    public function record(string $provider, array $event): PaymentEvent
    {
        return PaymentEvent::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => (string) ($event['id'] ?? '')],
            [
                'type' => (string) ($event['type'] ?? 'unknown'),
                'payload' => $event,
                'received_at' => now(),
            ],
        );
    }

    public function process(PaymentEvent $event): void
    {
        if ($event->isProcessed()) {
            return;
        }

        try {
            match ($event->type) {
                'payment_intent.succeeded', 'invoice.payment_succeeded' => $this->paymentSucceeded($event),
                'payment_intent.payment_failed', 'invoice.payment_failed' => $this->paymentFailed($event),
                'customer.subscription.deleted' => $this->subscriptionEnded($event),
                // Unrecognised events are stored and marked handled rather than retried forever.
                default => null,
            };

            $event->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $event->update(['error' => $e->getMessage()]);

            Log::error('Payment webhook failed', ['event' => $event->event_id, 'error' => $e->getMessage()]);
        }
    }

    private function paymentSucceeded(PaymentEvent $event): void
    {
        $reference = $this->reference($event);
        $invoiceNumber = data_get($event->payload, 'data.object.metadata.invoice_number');

        if ($invoiceNumber === null) {
            return;
        }

        $invoice = $this->tenancy->withoutScoping(
            fn () => Invoice::withoutGlobalScopes()->where('number', $invoiceNumber)->first(),
        );

        if ($invoice === null || $invoice->status === Invoice::PAID) {
            return;
        }

        $tenant = $this->tenantOf($invoice->tenant_id);

        $this->tenancy->runAs($tenant, function () use ($invoice, $reference): void {
            DB::transaction(function () use ($invoice, $reference): void {
                $this->invoices->markPaid($invoice, $reference);

                Subscription::query()->latest('id')->first()?->update([
                    'status' => Subscription::ACTIVE,
                    'grace_ends_at' => null,
                ]);
            });
        });

        $this->tenancy->withoutScoping(fn () => $tenant->update(['status' => Tenant::STATUS_ACTIVE]));
    }

    private function paymentFailed(PaymentEvent $event): void
    {
        // Recorded only. The retry ladder is a product decision and lives in DunningService, so
        // the provider's own retry behaviour cannot change what our customers experience.
        Log::warning('Payment failed webhook received', [
            'event' => $event->event_id,
            'invoice' => data_get($event->payload, 'data.object.metadata.invoice_number'),
        ]);
    }

    private function subscriptionEnded(PaymentEvent $event): void
    {
        $ref = data_get($event->payload, 'data.object.id');

        if ($ref === null) {
            return;
        }

        $subscription = $this->tenancy->withoutScoping(
            fn () => Subscription::withoutGlobalScopes()->where('provider_ref', $ref)->first(),
        );

        $subscription?->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now()]);
    }

    private function reference(PaymentEvent $event): ?string
    {
        return data_get($event->payload, 'data.object.id');
    }

    private function tenantOf(int $tenantId): Tenant
    {
        return $this->tenancy->withoutScoping(fn () => Tenant::query()->findOrFail($tenantId));
    }
}
