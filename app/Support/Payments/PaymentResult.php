<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * What happened when we tried to take money.
 *
 * Returned rather than thrown, because a declined card is an ordinary operational fact that
 * belongs in the collection trail — not an exception that stops a nightly run part-way through
 * everyone else's invoices.
 */
final class PaymentResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly string $provider,
        public readonly ?string $reference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
    ) {}

    public static function succeeded(string $provider, ?string $reference = null): self
    {
        return new self(true, $provider, $reference);
    }

    public static function failed(string $provider, string $code, string $message): self
    {
        return new self(false, $provider, null, $code, $message);
    }

    /**
     * Whether trying again could plausibly work.
     *
     * A card with no funds today may have funds on Friday. A card that has been closed will not
     * come back, and retrying it four more times only annoys the customer.
     */
    public function isWorthRetrying(): bool
    {
        return ! in_array($this->failureCode, [
            'card_declined_permanent', 'invalid_account', 'account_closed', 'do_not_honor_final',
        ], true);
    }

    /** What a customer should read, rather than a provider's error code. */
    public function customerMessage(): string
    {
        return match ($this->failureCode) {
            'insufficient_funds' => 'The payment was declined for insufficient funds.',
            'expired_card' => 'The card on file has expired.',
            'card_declined', 'card_declined_permanent' => 'The card was declined by the issuing bank.',
            'invalid_account', 'account_closed' => 'The payment method is no longer valid.',
            default => 'The payment could not be taken.',
        };
    }
}
