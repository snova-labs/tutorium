<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One metered segment of an invoice, with the day its quantity came from. */
final class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'plan_id', 'description', 'period_start', 'period_end',
        'quantity', 'quantity_basis_date', 'quantity_snapshot_id',
        'unit_price_minor', 'amount_minor', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'quantity_basis_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<UsageSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(UsageSnapshot::class, 'quantity_snapshot_id');
    }
}
