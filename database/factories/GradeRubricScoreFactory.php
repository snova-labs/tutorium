<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Grade;
use App\Models\GradeRubricScore;
use App\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GradeRubricScore> */
final class GradeRubricScoreFactory extends Factory
{
    protected $model = GradeRubricScore::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'grade_id' => Grade::factory(),
            'rubric_criterion_id' => RubricCriterion::factory(),
            'points' => 8,
        ];
    }
}
