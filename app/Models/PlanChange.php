<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PlanChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A move between plans — immediate for an upgrade, scheduled for a downgrade. */
final class PlanChange extends Model
{
    /** @use HasFactory<PlanChangeFactory> */
    use BelongsToTenant, HasFactory;

    public const UPGRADE = 'upgrade';

    public const DOWNGRADE = 'downgrade';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'from_plan_id', 'to_plan_id',
        'direction', 'effective_on', 'applied_at', 'reason', 'requested_by',
    ];

    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'applied_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function isPending(): bool
    {
        return $this->applied_at === null;
    }
}
