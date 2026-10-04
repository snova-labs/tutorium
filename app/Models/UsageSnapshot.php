<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\UsageSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One day's active-learner count, as measured.
 *
 * Never edited. A correction is a new row pointing at the one it corrects, so both figures stay
 * readable and an invoice can say which it used. A number that quietly changed is a number a
 * customer has to take on trust, and metering that requires trust is metering that generates
 * arguments.
 */
final class UsageSnapshot extends Model
{
    /** @use HasFactory<UsageSnapshotFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'snapshot_date', 'active_learners', 'breakdown',
        'is_correction', 'corrects_snapshot_id', 'correction_reason', 'corrected_by_operator_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'active_learners' => 'integer',
            'breakdown' => 'array',
            'is_correction' => 'boolean',
        ];
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException(
            'Usage snapshots are immutable. Record a correction instead, so both figures stay visible.',
        );
    }
}
