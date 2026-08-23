<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Course;
use App\Models\TimetableSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BatchService
{
    /** @param array<string, mixed> $attributes */
    public function create(Course $course, Branch $branch, array $attributes): Batch
    {
        return DB::transaction(function () use ($course, $branch, $attributes): Batch {
            $batch = new Batch($attributes);
            $batch->course()->associate($course);
            $batch->branch()->associate($branch);

            // A batch without an explicit timezone follows its branch. Defaulting to UTC instead
            // would schedule every session at the wrong hour and look correct while doing it.
            $batch->timezone = $attributes['timezone'] ?? $branch->timezone;
            $batch->status = $attributes['status'] ?? BatchStatus::Planned;

            $batch->save();

            return $batch->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Batch $batch, array $attributes): Batch
    {
        // Sessions already carry their own instants. Changing the batch timezone after they exist
        // would leave the two disagreeing, so it is refused rather than silently reinterpreted.
        if (isset($attributes['timezone'])
            && $attributes['timezone'] !== $batch->timezone
            && $batch->sessions()->exists()) {
            throw ValidationException::withMessages([
                'timezone' => 'This batch already has sessions. Changing its timezone now would move '
                    .'every one of them. Cancel the remaining sessions and regenerate them instead.',
            ]);
        }

        return DB::transaction(function () use ($batch, $attributes): Batch {
            $batch->update($attributes);

            return $batch->refresh();
        });
    }

    /** @param array<int, array{user_id: int, role?: string}> $teachers */
    public function assignTeachers(Batch $batch, array $teachers): Batch
    {
        DB::transaction(function () use ($batch, $teachers): void {
            $payload = [];

            foreach ($teachers as $teacher) {
                $payload[$teacher['user_id']] = [
                    'tenant_id' => $batch->tenant_id,
                    'role' => $teacher['role'] ?? 'lead',
                ];
            }

            $batch->teachers()->sync($payload);
        });

        return $batch->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function addSlot(Batch $batch, array $attributes): TimetableSlot
    {
        return DB::transaction(function () use ($batch, $attributes): TimetableSlot {
            $slot = new TimetableSlot($attributes);
            $slot->batch()->associate($batch);
            $slot->save();

            return $slot->refresh();
        });
    }

    /**
     * Copy a batch for the next intake: timetable and teachers, never learners or grades.
     *
     * Carrying enrollments across would be the wrong default in every case — the point of a new
     * intake is a new set of people.
     */
    public function cloneForNextIntake(Batch $batch, string $name, string $code, string $startsOn, ?string $endsOn = null): Batch
    {
        return DB::transaction(function () use ($batch, $name, $code, $startsOn, $endsOn): Batch {
            $copy = $batch->replicate(['code', 'name', 'starts_on', 'ends_on', 'status']);
            $copy->fill([
                'name' => $name,
                'code' => $code,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => BatchStatus::Planned,
            ]);
            $copy->save();

            foreach ($batch->timetableSlots as $slot) {
                $new = $slot->replicate(['effective_from', 'effective_to']);
                $new->batch_id = $copy->getKey();
                $new->effective_from = null;
                $new->effective_to = null;
                $new->save();
            }

            $copy->teachers()->sync(
                $batch->teachers->mapWithKeys(fn ($t) => [
                    $t->getKey() => ['tenant_id' => $batch->tenant_id, 'role' => $t->pivot->role],
                ])->all(),
            );

            return $copy->refresh();
        });
    }

    public function complete(Batch $batch): Batch
    {
        $batch->update(['status' => BatchStatus::Completed]);

        return $batch->refresh();
    }
}
