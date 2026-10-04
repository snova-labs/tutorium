<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Tenant;

/**
 * Taking money, behind one interface.
 *
 * Three implementations at the moment: Stripe, manual invoicing for customers who pay by transfer,
 * and a fake for tests and local development. Which one is in play is a configuration decision,
 * and no billing logic anywhere asks (SL-OPS-007 §5, ADR-001).
 */
interface PaymentProvider
{
    /** Create or update the provider's record of this customer. */
    public function syncCustomer(Tenant $tenant, BillingProfile $profile): string;

    /** A hosted page where a customer enters card details we never see. */
    public function checkoutUrl(Tenant $tenant, BillingProfile $profile, string $returnUrl): ?string;

    /** A hosted page where a customer manages their own payment method. */
    public function portalUrl(BillingProfile $profile, string $returnUrl): ?string;

    public function charge(Invoice $invoice, BillingProfile $profile): PaymentResult;

    /** Verify a webhook actually came from the provider. */
    public function verifyWebhook(string $payload, string $signature): bool;

    /**
     * Whether this provider takes payment by itself (a card on file). False means every invoice
     * is paid by transfer, so a card cannot be added or chosen.
     */
    public function collectsAutomatically(): bool;

    public function name(): string;
}
