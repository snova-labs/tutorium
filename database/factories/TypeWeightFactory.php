<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\TypeWeight;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TypeWeight> */
final class TypeWeightFactory extends Factory
{
    protected $model = TypeWeight::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'assessment_type_id' => AssessmentType::factory(),
            'weight_pct' => 40,
        ];
    }
}
