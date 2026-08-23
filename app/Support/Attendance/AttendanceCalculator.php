<?php

declare(strict_types=1);

namespace App\Support\Attendance;

use App\Enums\SessionStatus;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\MakeupLink;
use App\Support\Time\PeriodBoundary;
use Illuminate\Support\Collection;

/**
 * Turns marks into a number, under the rules in force.
 *
 * Four things this gets right that a `COUNT(*) / COUNT(*)` would not:
 *
 *  1. Only *held* sessions of *counted* types are opportunities. A cancelled class and a make-up
 *     class are both excluded, for opposite reasons.
 *  2. A learner is only measured against sessions that fell inside their own enrollment. Someone
 *     who joined in week three does not owe an explanation for weeks one and two.
 *  3. Excused leaves the denominator; Absent stays in it. That distinction is the difference
 *     between a fair figure and a punitive one.
 *  4. Late counts as attendance only where the policy allows it — the same marks, read two ways.
 */
final class AttendanceCalculator
{
    public function __construct(private readonly AttendancePolicyResolver $policies) {}

    public function forPeriod(Enrollment $enrollment, PeriodBoundary $period): AttendanceRate
    {
        $batch = $enrollment->batch;
        $policy = $this->policies->for($batch);

        $sessions = $this->opportunities($batch, $enrollment, $period, $policy);

        if ($sessions->isEmpty()) {
            return new AttendanceRate(0, 0, 0, 0, 0, $policy->isCompulsory);
        }

        $records = AttendanceRecord::query()
            ->with('status')
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('class_session_id', $sessions->modelKeys())
            ->get()
            ->keyBy('class_session_id');

        $attended = 0;
        $counted = 0;
        $unmarked = 0;
        $excused = 0;

        foreach ($sessions as $session) {
            $record = $records->get($session->getKey());

            if ($record === null) {
                $unmarked++;

                continue;
            }

            if (! $record->status->counts_in_rate) {
                $excused++;

                continue;
            }

            $counted++;

            if ($policy->treatsAsAttended($record->status->counts_as_attended, $record->status->is_late)) {
                $attended++;
            }
        }

        return new AttendanceRate(
            attended: $attended,
            counted: $counted,
            unmarked: $unmarked,
            excused: $excused,
            madeUp: $this->madeUpCount($enrollment, $sessions),
            isCompulsory: $policy->isCompulsory,
        );
    }

    /**
     * Rates for a whole batch in one pass, for the register summary and the at-risk list.
     *
     * @return Collection<int, array{enrollment: Enrollment, rate: AttendanceRate}>
     */
    public function forBatch(Batch $batch, PeriodBoundary $period): Collection
    {
        return $batch->loadMissing('course')->enrollmentsForAttendance()
            ->map(fn (Enrollment $enrollment) => [
                'enrollment' => $enrollment,
                'rate' => $this->forPeriod($enrollment->setRelation('batch', $batch), $period),
            ]);
    }

    /**
     * The sessions this learner could actually have attended.
     *
     * @return Collection<int, ClassSession>
     */
    private function opportunities(
        Batch $batch,
        Enrollment $enrollment,
        PeriodBoundary $period,
        ResolvedAttendancePolicy $policy,
    ): Collection {
        $from = $period->startsLocalDate->toDateString();
        $to = $period->endsLocalDate->toDateString();

        // A learner is not answerable for sessions before they joined or after they left.
        $enrolledFrom = $enrollment->enrolled_on?->toDateString();
        $enrolledTo = $enrollment->ended_on?->toDateString();

        return ClassSession::query()
            ->where('batch_id', $batch->getKey())
            ->where('status', SessionStatus::Held)
            ->whereBetween('session_local_date', [$from, $to])
            ->when($enrolledFrom !== null, fn ($q) => $q->where('session_local_date', '>=', $enrolledFrom))
            ->when($enrolledTo !== null, fn ($q) => $q->where('session_local_date', '<=', $enrolledTo))
            ->when(
                $policy->countedSessionTypeIds !== null,
                fn ($q) => $q->whereIn('session_type_id', $policy->countedSessionTypeIds),
            )
            ->get();
    }

    /** @param Collection<int, ClassSession> $sessions */
    private function madeUpCount(Enrollment $enrollment, Collection $sessions): int
    {
        return MakeupLink::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('missed_session_id', $sessions->modelKeys())
            ->count();
    }
}
