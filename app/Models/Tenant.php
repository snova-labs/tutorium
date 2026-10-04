<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A paying account and the isolation boundary for all tenant data.
 *
 * This is a control-plane model: it is deliberately NOT tenant-scoped.
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PURGED = 'purged';

    protected $fillable = [
        'name', 'slug', 'region_code', 'preset_code', 'status',
        'deployment_mode', 'contact_name', 'contact_email', 'locale',
        'trial_ends_at', 'trial_reminders_sent', 'trial_expired_at', 'suspended_at', 'purge_after',
        'onboarding_dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'immutable_datetime',
            'trial_reminders_sent' => 'array',
            'trial_expired_at' => 'immutable_datetime',
            'onboarding_dismissed_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'purge_after' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Brand, $this> */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Writable states. Suspended and beyond are read-only (SL-BIL-006 §6). */
    public function isOperational(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_PAST_DUE], true);
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, [self::STATUS_SUSPENDED, self::STATUS_CANCELLED], true);
    }

    public function isPurged(): bool
    {
        return $this->status === self::STATUS_PURGED;
    }
}
