<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Course
    {
        return DB::transaction(fn (): Course => Course::query()->create($attributes));
    }

    /** @param array<string, mixed> $attributes */
    public function update(Course $course, array $attributes): Course
    {
        // Changing the period type of a course that has already reported would redraw the
        // boundaries of periods people have been sent reports about.
        if (isset($attributes['period_type'])
            && $attributes['period_type'] !== $course->period_type->value
            && $course->reportingPeriods()->where('status', '!=', 'open')->exists()) {
            throw ValidationException::withMessages([
                'period_type' => 'This course has already reported on at least one period. '
                    .'Changing how periods are divided would move boundaries that reports were built on. '
                    .'Create a new course instead.',
            ]);
        }

        return DB::transaction(function () use ($course, $attributes): Course {
            $course->update($attributes);

            return $course->refresh();
        });
    }

    public function archive(Course $course): void
    {
        if ($course->batches()->whereIn('status', ['planned', 'running'])->exists()) {
            throw ValidationException::withMessages([
                'course' => 'This course still has planned or running batches. Complete or cancel them first.',
            ]);
        }

        DB::transaction(function () use ($course): void {
            $course->update(['is_active' => false]);
            $course->delete();
        });
    }
}
