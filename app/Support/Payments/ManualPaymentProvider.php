<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Tenant;

/**
 * Invoicing, for customers who pay by bank transfer.
 *
 * Not a fallback or a degraded mode. Institutions, government-funded programmes and academies
 * without a company card are a real part of the market, and several of them cannot use a card at
 * all. Charges are recorded as awaiting payment rather than attempted.
 */
final class ManualPaymentProvider implements PaymentProvider
{
    public function syncCustomer(Tenant $tenant, BillingProfile $profile): string
    {
        return 'manual:'.$tenant->slug;
    }

    public function checkoutUrl(Tenant $tenant, BillingProfile $profile, string $returnUrl): ?string
    {
        return null;
    }

    public function portalUrl(BillingProfile $profile, string $returnUrl): ?string
    {
        return null;
    }

    public function charge(Invoice $invoice, BillingProfile $profile): PaymentResult
    {
        // Nothing is attempted. The invoice is issued and someone marks it paid when the transfer
        // arrives, which is exactly how these customers already work.
        return PaymentResult::failed(
            $this->name(),
            'awaiting_transfer',
            'Awaiting bank transfer. This invoice is not collected automatically.',
        );
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        return false;
    }

    public function collectsAutomatically(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'manual';
    }
}
