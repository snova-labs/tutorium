<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\MakeupLink;
use App\Support\Attendance\AttendancePolicyResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording attendance.
 *
 * The whole register saves in one transaction. A teacher marking forty learners on a phone in a
 * classroom must not end up with half a register committed because the connection dropped
 * mid-request (FR-ATT-3).
 */
final class AttendanceService
{
    public function __construct(private readonly AttendancePolicyResolver $policies) {}

    /**
     * The roster a register screen renders: every enrolled learner, with any mark already made.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function roster(ClassSession $session): Collection
    {
        $enrollments = Enrollment::query()
            ->with(['learner'])
            ->where('batch_id', $session->batch_id)
            ->active()
            // Someone who joined after this session happened does not belong on its register.
            ->where('enrolled_on', '<=', $session->session_local_date->toDateString())
            ->get()
            ->sortBy(fn (Enrollment $e) => $e->learner->sort_name ?: $e->learner->legal_name)
            ->values();

        $records = AttendanceRecord::query()
            ->where('class_session_id', $session->getKey())
            ->get()
            ->keyBy('enrollment_id');

        return $enrollments->map(function (Enrollment $enrollment) use ($records): array {
            $record = $records->get($enrollment->getKey());

            return [
                'enrollment_id' => $enrollment->getKey(),
                'learner_id' => $enrollment->learner_id,
                'number' => $enrollment->learner->number,
                'name' => $enrollment->learner->displayName(),
                'status_id' => $record?->status_id,
                'minutes_late' => $record?->minutes_late,
                'note' => $record?->note,
                'marked' => $record !== null,
            ];
        });
    }

    /**
     * Save a whole register.
     *
     * @param array<int, array{enrollment_id: int, status_id: int, minutes_late?: int|null, note?: string|null}> $marks
     * @return array{saved: int, updated: int, session_id: int}
     */
    public function record(ClassSession $session, array $marks): array
    {
        if ($session->status === SessionStatus::Cancelled) {
            throw ValidationException::withMessages([
                'session' => 'This session was cancelled, so there is no attendance to record. '
                    .'Reinstate it first if it went ahead after all.',
            ]);
        }

        $validEnrollments = Enrollment::query()
            ->where('batch_id', $session->batch_id)
            ->pluck('id')
            ->flip();

        $statuses = AttendanceStatus::query()->get()->keyBy('id');
        $policy = $this->policies->for($session->batch);

        return DB::transaction(function () use ($session, $marks, $validEnrollments, $statuses, $policy): array {
            $saved = 0;
            $updated = 0;

            foreach ($marks as $mark) {
                if (! $validEnrollments->has($mark['enrollment_id'])) {
                    throw ValidationException::withMessages([
                        'marks' => 'One of these learners is not enrolled in this batch.',
                    ]);
                }

                if (! $statuses->has($mark['status_id'])) {
                    throw ValidationException::withMessages(['marks' => 'Unknown attendance status.']);
                }

                $existing = AttendanceRecord::query()
                    ->where('class_session_id', $session->getKey())
                    ->where('enrollment_id', $mark['enrollment_id'])
                    ->first();

                $attributes = [
                    'status_id' => $mark['status_id'],
                    'minutes_late' => $this->minutesLate($mark, $statuses->get($mark['status_id'])),
                    'note' => $mark['note'] ?? null,
                    'recorded_by' => Auth::id(),
                    'recorded_at' => now(),
                ];

                if ($existing !== null) {
                    $existing->update($attributes);
                    $updated++;

                    continue;
                }

                AttendanceRecord::query()->create($attributes + [
                    'class_session_id' => $session->getKey(),
                    'enrollment_id' => $mark['enrollment_id'],
                ]);
                $saved++;
            }

            // Marking a register is the act that makes a scheduled session a held one. Asking a
            // teacher to do that separately would guarantee it is sometimes forgotten, and every
            // attendance figure downstream depends on it.
            if ($session->status === SessionStatus::Scheduled) {
                $session->update(['status' => SessionStatus::Held]);
            }

            unset($policy);

            return ['saved' => $saved, 'updated' => $updated, 'session_id' => $session->getKey()];
        });
    }

    /** Mark every unmarked learner in one action — the "all present" button. */
    public function markRemaining(ClassSession $session, AttendanceStatus $status): array
    {
        $marks = $this->roster($session)
            ->reject(fn (array $row) => $row['marked'])
            ->map(fn (array $row) => ['enrollment_id' => $row['enrollment_id'], 'status_id' => $status->getKey()])
            ->values()
            ->all();

        return $this->record($session, $marks);
    }

    public function linkMakeup(Enrollment $enrollment, ClassSession $missed, ClassSession $makeup, ?string $note = null): MakeupLink
    {
        if ($missed->batch_id !== $enrollment->batch_id) {
            throw ValidationException::withMessages([
                'missed_session_id' => 'That session belongs to a different batch.',
            ]);
        }

        return MakeupLink::query()->updateOrCreate(
            ['enrollment_id' => $enrollment->getKey(), 'missed_session_id' => $missed->getKey()],
            ['makeup_session_id' => $makeup->getKey(), 'note' => $note],
        );
    }

    /**
     * @param array<string, mixed> $mark
     */
    private function minutesLate(array $mark, AttendanceStatus $status): ?int
    {
        // Minutes only mean something on a late-type mark. Storing them against "Present" would
        // leave a figure nobody can interpret later.
        return $status->is_late ? ($mark['minutes_late'] ?? null) : null;
    }
}
