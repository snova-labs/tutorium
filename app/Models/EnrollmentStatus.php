<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EnrollmentStatusFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The status of one learner in one batch.
 *
 * `is_active_for_billing` is the flag the metering job counts. Putting it on an editable row
 * means a tenant can invent "On hold — unpaid" without anyone touching billing code, and can see
 * for themselves exactly which statuses cost money.
 */
final class EnrollmentStatus extends Model
{
    /** @use HasFactory<EnrollmentStatusFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /** Set by a transfer, never chosen directly: a transfer also creates the new enrollment. */
    public const TRANSFERRED = 'TRANSFERRED';

    protected $fillable = ['tenant_id', 'name', 'code', 'is_active_for_billing', 'is_terminal', 'color', 'sort'];

    protected function casts(): array
    {
        return [
            'is_active_for_billing' => 'boolean',
            'is_terminal' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_active_for_billing', true);
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
