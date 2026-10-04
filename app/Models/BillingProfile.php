<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BillingProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Who to bill, and a reference to the payment method the provider holds.
 *
 * No card data. `method_last_four` and the expiry exist so a customer can recognise their own card
 * on a screen; neither could be used to charge anything.
 */
final class BillingProfile extends Model
{
    /** @use HasFactory<BillingProfileFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'legal_name', 'billing_email', 'tax_id', 'country', 'address',
        'provider', 'customer_ref', 'method_brand', 'method_last_four',
        'method_exp_month', 'method_exp_year', 'prefers_invoicing',
    ];

    protected function casts(): array
    {
        return ['address' => 'array', 'prefers_invoicing' => 'boolean'];
    }

    public function hasPaymentMethod(): bool
    {
        return $this->method_last_four !== null;
    }

    /** Cards are valid to the end of their expiry month. */
    public function methodExpired(): bool
    {
        $end = $this->methodExpiresAt();

        return $end !== null && $end->isPast();
    }

    /** Within a month of expiring, so there is time to replace it before a charge fails. */
    public function methodExpiresSoon(): bool
    {
        $end = $this->methodExpiresAt();

        return $end !== null && ! $end->isPast() && $end->lte(now()->addMonth());
    }

    /** A card that could be charged today. */
    public function hasUsablePaymentMethod(): bool
    {
        return $this->hasPaymentMethod() && ! $this->methodExpired();
    }

    public function methodSummary(): ?string
    {
        return $this->hasPaymentMethod()
            ? sprintf(
                '%s •••• %s, expires %02d/%d',
                $this->method_brand ?? 'Card',
                $this->method_last_four,
                $this->method_exp_month,
                $this->method_exp_year,
            )
            : null;
    }

    private function methodExpiresAt(): ?CarbonImmutable
    {
        if (! $this->hasPaymentMethod() || $this->method_exp_month === null || $this->method_exp_year === null) {
            return null;
        }

        return CarbonImmutable::create((int) $this->method_exp_year, (int) $this->method_exp_month, 1)?->endOfMonth();
    }

    public function auditModule(): string
    {
        return 'Billing';
    }

    /**
     * Nothing about a payment method belongs in an activity log.
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return [
            'customer_ref', 'method_brand', 'method_last_four',
            'method_exp_month', 'method_exp_year', 'tax_id',
            'created_at', 'updated_at',
        ];
    }
}
