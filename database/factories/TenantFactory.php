<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Sample Academy '.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'region_code' => 'default',
            'preset_code' => 'blank',
            'status' => Tenant::STATUS_ACTIVE,
            'deployment_mode' => 'cloud',
            'contact_name' => 'Sample Contact',
            'contact_email' => fake()->unique()->safeEmail(),
            'locale' => 'en',
        ];
    }

    public function trial(): self
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
    }
}
