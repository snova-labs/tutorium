<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
final class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'batch_id' => Batch::factory(),
            'number' => 'ENR-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'enrolled_on' => '2026-08-01',
            'status_id' => EnrollmentStatus::factory(),
        ];
    }
}
