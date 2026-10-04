<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\ClassSession;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRecord> */
final class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'class_session_id' => ClassSession::factory(),
            'enrollment_id' => Enrollment::factory(),
            'status_id' => AttendanceStatus::factory(),
            'recorded_at' => now(),
        ];
    }
}
