<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnrollmentStatusHistory> */
final class EnrollmentStatusHistoryFactory extends Factory
{
    protected $model = EnrollmentStatusHistory::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'from_status_id' => null,
            'to_status_id' => EnrollmentStatus::factory(),
            'reason' => 'Enrolled',
            'changed_at' => now(),
        ];
    }
}
