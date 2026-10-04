<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => 'plan-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Sample Plan',
            'currency' => 'EUR',
            'unit_price_minor' => 200,
            'minimum_charge_minor' => 0,
        ];
    }
}
