<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Tenant;

/**
 * A provider that does what the test tells it to.
 *
 * Lives in the application rather than the test suite so that local development can exercise the
 * whole billing path — dunning, recovery, suspension — without a Stripe account or a network.
 * Every behaviour that matters is testable; only the HTTP calls are not.
 */
final class FakePaymentProvider implements PaymentProvider
{
    /** @var array<int, PaymentResult> */
    private array $queued = [];

    private PaymentResult $default;

    /** @var array<int, Invoice> */
    public array $charged = [];

    public function __construct()
    {
        $this->default = PaymentResult::succeeded('fake', 'fake-ref');
    }

    public function willSucceed(?string $reference = null): self
    {
        $this->default = PaymentResult::succeeded($this->name(), $reference ?? 'fake-ref');

        return $this;
    }

    public function willFail(string $code = 'card_declined', string $message = 'Declined'): self
    {
        $this->default = PaymentResult::failed($this->name(), $code, $message);

        return $this;
    }

    /** Queue a sequence, for testing a run of failures followed by a recovery. */
    public function queue(PaymentResult ...$results): self
    {
        $this->queued = array_merge($this->queued, $results);

        return $this;
    }

    public function syncCustomer(Tenant $tenant, BillingProfile $profile): string
    {
        return 'fake_cus_'.$tenant->getKey();
    }

    public function checkoutUrl(Tenant $tenant, BillingProfile $profile, string $returnUrl): string
    {
        return 'https://payments.test/checkout/'.$tenant->slug;
    }

    public function portalUrl(BillingProfile $profile, string $returnUrl): string
    {
        return 'https://payments.test/portal/'.$profile->customer_ref;
    }

    public function charge(Invoice $invoice, BillingProfile $profile): PaymentResult
    {
        $this->charged[] = $invoice;

        return array_shift($this->queued) ?? $this->default;
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        return $signature === 'valid-signature';
    }

    public function collectsAutomatically(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake';
    }
}
