<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LearnerStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearnerStatus> */
final class LearnerStatusFactory extends Factory
{
    protected $model = LearnerStatus::class;

    public function definition(): array
    {
        return [
            'name' => 'Active',
            'code' => 'ACTIVE'.fake()->unique()->numberBetween(1, 9999),
            'is_terminal' => false,
            'sort' => 0,
        ];
    }
}
