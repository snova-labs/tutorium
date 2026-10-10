<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusHistory;
use App\Models\Learner;
use App\Support\Sequences\IdSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that puts a learner into a batch, or moves them out of one.
 *
 * Status changes always go through here rather than a bare update, because each one must leave a
 * history row: metering reconstructs missed days from that history, and billing disputes are
 * settled by reading it.
 */
final class EnrollmentService
{
    public function __construct(private readonly IdSequenceService $sequences) {}

    public function enroll(Learner $learner, Batch $batch, ?string $enrolledOn = null, ?EnrollmentStatus $status = null): Enrollment
    {
        $this->guardBatchAccepts($batch);
        $this->guardNotAlreadyEnrolled($learner, $batch);
        $this->guardCapacity($batch);

        return DB::transaction(function () use ($learner, $batch, $enrolledOn, $status): Enrollment {
            $status ??= $this->defaultStatus();

            $enrollment = Enrollment::query()->create([
                'learner_id' => $learner->getKey(),
                'batch_id' => $batch->getKey(),
                'number' => $this->sequences->next('enrollment'),
                // Defaults to the batch's local today, not the server's — a coordinator enrolling
                // a Toronto learner at 9pm Kathmandu time means today in Toronto.
                'enrolled_on' => $enrolledOn ?? $batch->localNow()->toDateString(),
                'status_id' => $status->getKey(),
            ]);

            $this->recordHistory($enrollment, null, $status, 'Enrolled');

            return $enrollment->refresh();
        });
    }

    public function changeStatus(Enrollment $enrollment, EnrollmentStatus $status, ?string $reason = null): Enrollment
    {
        if ($enrollment->status_id === $status->getKey()) {
            return $enrollment;
        }

        return DB::transaction(function () use ($enrollment, $status, $reason): Enrollment {
            $from = $enrollment->status;

            $enrollment->update([
                'status_id' => $status->getKey(),
                'status_reason' => $reason,
                'ended_on' => $status->is_terminal ? now()->toDateString() : null,
            ]);

            $this->recordHistory($enrollment, $from, $status, $reason);

            return $enrollment->refresh();
        });
    }

    /**
     * Move a learner to another batch.
     *
     * The old enrollment is closed rather than edited, and the new one starts empty. Attendance
     * and grades stay attached to the enrollment where they happened — a learner who moves in
     * October does not retroactively acquire September's attendance in a batch they never sat in.
     */
    public function transfer(Enrollment $enrollment, Batch $target, ?string $reason = null): Enrollment
    {
        if ($enrollment->batch_id === $target->getKey()) {
            throw ValidationException::withMessages([
                'batch' => 'That learner is already in this batch.',
            ]);
        }

        $this->guardBatchAccepts($target);
        $this->guardNotAlreadyEnrolled($enrollment->learner, $target);
        $this->guardCapacity($target);

        return DB::transaction(function () use ($enrollment, $target, $reason): Enrollment {
            $transferred = $this->statusByCode(EnrollmentStatus::TRANSFERRED) ?? $this->statusByCode('COMPLETED');

            $new = $this->enroll($enrollment->learner, $target);

            $from = $enrollment->status;
            $enrollment->update([
                'status_id' => $transferred->getKey(),
                'status_reason' => $reason ?? 'Transferred to '.$target->name,
                'ended_on' => now()->toDateString(),
                'transferred_to_enrollment_id' => $new->getKey(),
            ]);

            $this->recordHistory($enrollment, $from, $transferred, $reason ?? 'Transferred to '.$target->name);

            return $new;
        });
    }

    public function withdraw(Enrollment $enrollment, string $reason): Enrollment
    {
        $status = $this->statusByCode('WITHDRAWN');

        if ($status === null) {
            throw ValidationException::withMessages([
                'status' => 'No withdrawal status is configured for this account.',
            ]);
        }

        return $this->changeStatus($enrollment, $status, $reason);
    }

    private function recordHistory(Enrollment $enrollment, ?EnrollmentStatus $from, EnrollmentStatus $to, ?string $reason): void
    {
        EnrollmentStatusHistory::query()->create([
            'enrollment_id' => $enrollment->getKey(),
            'from_status_id' => $from?->getKey(),
            'to_status_id' => $to->getKey(),
            'reason' => $reason,
            'changed_by' => Auth::id(),
            'changed_at' => now(),
        ]);
    }

    private function guardBatchAccepts(Batch $batch): void
    {
        if (! $batch->status->acceptsEnrollments()) {
            throw ValidationException::withMessages([
                'batch' => sprintf('%s is %s and is not taking enrollments.', $batch->name, $batch->status->value),
            ]);
        }
    }

    private function guardNotAlreadyEnrolled(Learner $learner, Batch $batch): void
    {
        $existing = Enrollment::query()
            ->where('learner_id', $learner->getKey())
            ->where('batch_id', $batch->getKey())
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'learner' => sprintf(
                    '%s is already enrolled in %s (%s).',
                    $learner->displayName(),
                    $batch->name,
                    $existing->status->name,
                ),
            ]);
        }
    }

    private function guardCapacity(Batch $batch): void
    {
        if ($batch->capacity === null) {
            return;
        }

        $current = Enrollment::query()->where('batch_id', $batch->getKey())->active()->count();

        if ($current >= $batch->capacity) {
            throw ValidationException::withMessages([
                'batch' => sprintf(
                    '%s is full — %d of %d places taken. Raise the capacity or choose another batch.',
                    $batch->name,
                    $current,
                    $batch->capacity,
                ),
            ]);
        }
    }

    private function defaultStatus(): EnrollmentStatus
    {
        return $this->statusByCode('ACTIVE')
            ?? EnrollmentStatus::query()->orderBy('sort')->firstOrFail();
    }

    private function statusByCode(string $code): ?EnrollmentStatus
    {
        return EnrollmentStatus::query()->where('code', $code)->first();
    }
}
