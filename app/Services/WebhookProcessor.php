<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WebhookEvent;
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
 *
 * Invoice numbers come from a per-tenant sequence, so every tenant has an INV-00001. An event is
 * only ever applied inside the tenant its metadata names, never matched on the number alone.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly InvoiceComposer $invoices,
        private readonly DunningService $dunning,
    ) {}

    /** @param array<string, mixed> $event */
    public function record(string $provider, array $event): WebhookEvent
    {
        return WebhookEvent::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => (string) ($event['id'] ?? '')],
            [
                'type' => (string) ($event['type'] ?? 'unknown'),
                'payload' => $event,
                'received_at' => now(),
            ],
        );
    }

    /**
     * Act on a recorded event. False when it failed, so the caller can ask the provider to retry.
     */
    public function process(WebhookEvent $event): bool
    {
        if ($event->isProcessed()) {
            return true;
        }

        $object = $event->payload['data']['object'] ?? [];

        try {
            match ($event->type) {
                'invoice.paid', 'invoice.payment_succeeded', 'payment_intent.succeeded' => $this->paymentSucceeded($object),
                'invoice.payment_failed', 'payment_intent.payment_failed' => $this->paymentFailed($event, $object),
                'setup_intent.succeeded', 'payment_method.attached' => $this->methodAttached($object),
                'customer.subscription.deleted' => $this->subscriptionEnded($object),
                // Unrecognised events are stored and marked handled rather than retried forever.
                default => null,
            };

            $event->update(['processed_at' => now(), 'error' => null]);

            return true;
        } catch (Throwable $e) {
            $event->update(['error' => $e->getMessage()]);

            Log::error('Payment webhook failed', ['event' => $event->event_id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** @param array<string, mixed> $object */
    private function paymentSucceeded(array $object): void
    {
        $tenant = $this->tenantNamedBy($object);
        $number = $object['metadata']['invoice_number'] ?? null;

        if ($tenant === null || $number === null) {
            return;
        }

        $settled = $this->tenancy->runAs($tenant, function () use ($number, $object): bool {
            $invoice = Invoice::query()->where('number', $number)->first();

            if ($invoice === null || $invoice->status === Invoice::PAID) {
                return false;
            }

            DB::transaction(function () use ($invoice, $object): void {
                $this->invoices->markPaid($invoice, $object['id'] ?? null);

                Subscription::query()->latest('id')->first()?->update(['status' => Subscription::ACTIVE]);
            });

            return true;
        });

        // Only an account that billing itself restricted is restored. A trial, or an account the
        // customer cancelled, is not reopened by a late payment.
        if ($settled && in_array($tenant->status, [Tenant::STATUS_PAST_DUE, Tenant::STATUS_SUSPENDED], true)) {
            $this->dunning->setTenantStatus($tenant, Tenant::STATUS_ACTIVE, 'Payment received.');
        }
    }

    /** @param array<string, mixed> $object */
    private function paymentFailed(WebhookEvent $event, array $object): void
    {
        // Recorded only. The retry ladder is a product decision and lives in DunningService, so
        // the provider's own retry behaviour cannot change what our customers experience.
        Log::warning('Payment failed webhook received', [
            'event' => $event->event_id,
            'invoice' => $object['metadata']['invoice_number'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $object */
    private function methodAttached(array $object): void
    {
        $profile = $this->profileFor($object['customer'] ?? null);

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
    private function subscriptionEnded(array $object): void
    {
        $profile = $this->profileFor($object['customer'] ?? null);

        if ($profile === null) {
            return;
        }

        $tenant = $this->tenancy->withoutScoping(fn () => Tenant::query()->find($profile->tenant_id));

        if ($tenant === null) {
            return;
        }

        $this->tenancy->runAs($tenant, function () use ($object): void {
            $subscription = Subscription::query()->where('provider_ref', $object['id'] ?? '')->first()
                ?? Subscription::query()->latest('id')->first();

            $subscription?->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now()]);
        });
    }

    /** @param array<string, mixed> $object */
    private function tenantNamedBy(array $object): ?Tenant
    {
        $tenantId = $object['metadata']['tenant_id'] ?? null;

        if ($tenantId === null) {
            return null;
        }

        return $this->tenancy->withoutScoping(fn () => Tenant::query()->find((int) $tenantId));
    }

    private function profileFor(?string $customerRef): ?BillingProfile
    {
        if ($customerRef === null) {
            return null;
        }

        return $this->tenancy->withoutScoping(
            fn () => BillingProfile::withoutGlobalScopes()->where('customer_ref', $customerRef)->first(),
        );
    }
}
