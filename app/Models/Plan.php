<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an account pays and what it may do.
 *
 * A control-plane model, deliberately not tenant-owned: plans are the platform's, and a tenant
 * reads its own through the entitlement interface rather than by querying this table.
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'currency', 'unit_price_minor', 'minimum_charge_minor',
        'interval', 'is_public', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'minimum_charge_minor' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
