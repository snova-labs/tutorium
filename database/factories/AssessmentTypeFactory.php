<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssessmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssessmentType> */
final class AssessmentTypeFactory extends Factory
{
    protected $model = AssessmentType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Homework',
            'code' => 'HW'.fake()->unique()->numberBetween(1, 9999),
            'counts_in_submission_rate' => true,
            'sort' => 0,
            'is_active' => true,
        ];
    }
}
