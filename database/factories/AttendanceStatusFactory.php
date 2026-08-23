<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendanceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceStatus> */
final class AttendanceStatusFactory extends Factory
{
    protected $model = AttendanceStatus::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Present',
            'code' => 'PRESENT'.fake()->unique()->numberBetween(1, 9999),
            'counts_as_attended' => true,
            'counts_in_rate' => true,
            'is_late' => false,
            'is_negative' => false,
            'sort' => 0,
            'is_active' => true,
        ];
    }

    public function present(): self
    {
        return $this->state(fn () => ['name' => 'Present', 'code' => 'PRESENT']);
    }

    /** Attended, but late — whether it counts is the policy's decision, not this flag's. */
    public function late(): self
    {
        return $this->state(fn () => [
            'name' => 'Late', 'code' => 'LATE',
            'counts_as_attended' => true, 'counts_in_rate' => true, 'is_late' => true,
        ]);
    }

    public function absent(): self
    {
        return $this->state(fn () => [
            'name' => 'Absent', 'code' => 'ABSENT',
            'counts_as_attended' => false, 'counts_in_rate' => true, 'is_negative' => true,
        ]);
    }

    /** Leaves the denominator entirely — not scored zero. */
    public function excused(): self
    {
        return $this->state(fn () => [
            'name' => 'Excused', 'code' => 'EXCUSED',
            'counts_as_attended' => false, 'counts_in_rate' => false,
        ]);
    }
}
