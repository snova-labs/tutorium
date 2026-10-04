<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RubricCriterion> */
final class RubricCriterionFactory extends Factory
{
    protected $model = RubricCriterion::class;

    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'name' => 'Sample criterion',
            'max_points' => 10,
            'sort' => 0,
        ];
    }
}
