<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Batch;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assessment> */
final class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'assessment_type_id' => AssessmentType::factory(),
            'grading_scheme_id' => GradingScheme::factory(),
            'number' => 'ASM-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'title' => 'Sample assessment',
            'due_local_date' => '2026-08-15',
            'max_points' => 20,
            'is_published' => true,
        ];
    }

    public function draft(): self
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
