<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A location or delivery unit, and the origin of local time rules.
 *
 * Timezone, week start and weekend days live here because they differ per location, not per
 * account: one tenant may run a Kathmandu branch (Sunday week start) and a Dubai branch
 * (Friday–Saturday weekend) at once.
 */
final class Branch extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'brand_id', 'name', 'code', 'timezone',
        'week_start', 'weekend_days', 'address', 'phone', 'email', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekend_days' => 'array',
            'address' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function auditModule(): string
    {
        return 'Organisation';
    }
}
