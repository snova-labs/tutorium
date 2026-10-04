<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TenantEntitlementOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantEntitlementOverride> */
final class TenantEntitlementOverrideFactory extends Factory
{
    protected $model = TenantEntitlementOverride::class;

    public function definition(): array
    {
        return [
            'feature_key' => 'max_branches',
            'value' => ['limit' => 5],
            'reason' => 'Agreed during a sales call.',
        ];
    }
}
