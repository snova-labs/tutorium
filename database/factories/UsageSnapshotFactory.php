<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UsageSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageSnapshot> */
final class UsageSnapshotFactory extends Factory
{
    protected $model = UsageSnapshot::class;

    public function definition(): array
    {
        return [
            'snapshot_date' => fake()->unique()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'active_learners' => 50,
        ];
    }
}
