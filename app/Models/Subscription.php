<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'plan_id', 'pending_plan_id', 'pending_plan_starts_on', 'provider', 'provider_ref', 'status',
        'current_period_start', 'current_period_end', 'cancel_at_period_end', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'pending_plan_starts_on' => 'immutable_date',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /** Past due still counts: service continues while we try to collect (SL-BIL-006 §6). */
    public function entitlesService(): bool
    {
        return in_array($this->status, [self::TRIALING, self::ACTIVE, self::PAST_DUE], true);
    }
}
