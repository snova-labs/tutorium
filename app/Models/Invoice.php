<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill, with its own reasoning attached.
 *
 * `quantity_snapshot_id` and `quantity_basis_date` are the point: a customer disputing a figure can
 * be shown the exact day it came from and check it against their own roster (FR-BIL-4).
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTenant, HasFactory;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'number', 'period_start', 'period_end',
        'quantity', 'quantity_basis_date', 'quantity_snapshot_id',
        'currency', 'unit_price_minor', 'subtotal_minor', 'minimum_adjustment_minor',
        'tax_minor', 'total_minor', 'tax_note', 'status', 'provider', 'provider_ref',
        'notes', 'issued_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'quantity_basis_date' => 'immutable_date',
            'notes' => 'array',
            'issued_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UsageSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(UsageSnapshot::class, 'quantity_snapshot_id');
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * One line per metered segment; more than one when the plan changed inside the period.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** Issued invoices are immutable; a change is a credit note, never an edit. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function total(): string
    {
        return number_format($this->total_minor / 100, 2).' '.$this->currency;
    }
}
