<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\RecipientType;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ReportDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to get one report to one person.
 *
 * A row per recipient rather than a status on the report, because a household with two parents is
 * two deliveries, and "she got it, he didn't" is a real and common situation.
 */
final class ReportDelivery extends Model
{
    /** @use HasFactory<ReportDeliveryFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'report_id', 'recipient_type', 'recipient_id', 'recipient_name',
        'to_address', 'channel', 'status', 'provider', 'provider_ref', 'error', 'retry_count', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_type' => RecipientType::class,
            'status' => DeliveryStatus::class,
            'sent_at' => 'immutable_datetime',
            'retry_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function markSent(?string $providerRef, string $provider): void
    {
        $this->update([
            'status' => DeliveryStatus::Sent,
            'provider' => $provider,
            'provider_ref' => $providerRef,
            'error' => null,
            'sent_at' => now(),
        ]);
    }

    public function markFailed(string $error, string $provider): void
    {
        $this->update([
            'status' => DeliveryStatus::Failed,
            'provider' => $provider,
            'error' => $error,
            'retry_count' => $this->retry_count + 1,
        ]);
    }
}
