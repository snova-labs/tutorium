<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanChange> */
final class PlanChangeFactory extends Factory
{
    protected $model = PlanChange::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'from_plan_id' => Plan::factory(),
            'to_plan_id' => Plan::factory(),
            'direction' => PlanChange::UPGRADE,
            'effective_on' => '2026-09-15',
            'applied_at' => now(),
        ];
    }
}
