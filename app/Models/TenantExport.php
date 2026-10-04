<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TenantExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A complete copy of an account's data.
 *
 * Available on every plan and in every account state, including suspension. A customer's records
 * are theirs, and a product that withholds them over an unpaid invoice deserves the chargeback
 * (SL-BIL-006 §6).
 */
final class TenantExport extends Model
{
    /** @use HasFactory<TenantExportFactory> */
    use BelongsToTenant, HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'status', 'file_path', 'file_disk', 'size_bytes', 'manifest', 'error',
        'requested_by_user_id', 'requested_by_operator_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
