<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SubmissionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Grade> */
final class GradeFactory extends Factory
{
    protected $model = Grade::class;

    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'enrollment_id' => Enrollment::factory(),
            'submission_status_id' => SubmissionStatus::factory(),
            'raw_score' => 16,
            'normalized_pct' => 80,
            'graded_at' => now(),
        ];
    }
}
