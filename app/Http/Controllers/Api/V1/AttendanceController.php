<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\SaveAttendanceRequest;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Services\AttendanceService;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendancePolicyResolver;
use App\Support\Time\PeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendancePolicyResolver $policies,
        private readonly PeriodService $periods,
    ) {}

    /**
     * Everything a register screen needs, in one request.
     *
     * Deliberately one call and a small payload: this is the screen a teacher opens on a phone in
     * a classroom, sometimes on a weak connection, with a class waiting (FR-ATT-5).
     */
    public function roster(Request $request, ClassSession $session): JsonResponse
    {
        abort_unless($request->user()->can('viewRoster', $session), 403);

        $session->load(['batch', 'sessionType']);
        $policy = $this->policies->for($session->batch);

        return response()->json([
            'data' => [
                'session' => [
                    'id' => $session->getKey(),
                    'batch' => ['id' => $session->batch_id, 'name' => $session->batch->name],
                    'type' => $session->sessionType->name,
                    'local' => [
                        'date' => $session->session_local_date->toDateString(),
                        'time' => substr((string) $session->start_time_local, 0, 5),
                        'timezone' => $session->batch->timezone,
                    ],
                    'status' => $session->status->value,
                ],

                // Printed above the register on purpose. A teacher deciding between Late and
                // Absent should be able to see what the system will make of either.
                'policy' => $policy->toArray(),
                'counts_in_rate' => $policy->countsSessionType((int) $session->session_type_id),

                'statuses' => AttendanceStatus::query()
                    ->where('is_active', true)->orderBy('sort')->get()
                    ->map(fn (AttendanceStatus $s) => [
                        'id' => $s->getKey(),
                        'name' => $s->name,
                        'code' => $s->code,
                        'counts_as_attended' => $s->counts_as_attended,
                        'counts_in_rate' => $s->counts_in_rate,
                        'is_late' => $s->is_late,
                        'color' => $s->color,
                    ]),

                'roster' => $this->attendance->roster($session),
            ],
        ]);
    }

    public function save(SaveAttendanceRequest $request, ClassSession $session): JsonResponse
    {
        $result = $this->attendance->record($session, $request->validated()['marks']);

        return response()->json([
            'data' => $result + [
                'message' => sprintf(
                    '%d recorded, %d updated.',
                    $result['saved'],
                    $result['updated'],
                ),
            ],
        ]);
    }

    /** The "everyone present" action, which only touches learners not already marked. */
    public function markRemaining(Request $request, ClassSession $session): JsonResponse
    {
        abort_unless($request->user()->can('record', $session), 403);

        $validated = $request->validate([
            'status_id' => ['required', 'integer', 'exists:attendance_statuses,id'],
        ]);

        $result = $this->attendance->markRemaining(
            $session,
            AttendanceStatus::query()->findOrFail($validated['status_id']),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Attendance for a whole batch over a period, with the arithmetic visible.
     */
    public function summary(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);

        $batch->load('course');

        $period = $request->filled('period')
            ? $this->periods->forLabel($batch, $request->string('period')->toString())
            : $this->periods->current($batch);

        $policy = $this->policies->for($batch);

        $rows = Enrollment::query()
            ->with(['learner', 'status'])
            ->where('batch_id', $batch->getKey())
            ->active()
            ->get()
            ->map(function (Enrollment $enrollment) use ($batch, $period, $policy): array {
                $rate = $this->calculator->forPeriod($enrollment->setRelation('batch', $batch), $period);

                return [
                    'enrollment_id' => $enrollment->getKey(),
                    'learner' => [
                        'id' => $enrollment->learner_id,
                        'number' => $enrollment->learner->number,
                        'name' => $enrollment->learner->displayName(),
                    ],
                    'attendance' => $rate->toArray(),
                    'below_threshold' => $rate->isBelow($policy->lowThresholdPct),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'period' => $period->toArray(),
                'policy' => $policy->toArray(),
                'learners' => $rows,
                'unmarked_sessions' => $rows->sum(fn (array $r) => $r['attendance']['unmarked']),
                'at_risk' => $rows->where('below_threshold', true)->count(),
            ],
        ]);
    }

    /** One learner's attendance for a period — the drill-down behind a summary row. */
    public function forEnrollment(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless($request->user()->can('view', $enrollment), 403);

        $enrollment->load(['batch.course', 'learner']);

        $period = $request->filled('period')
            ? $this->periods->forLabel($enrollment->batch, $request->string('period')->toString())
            : $this->periods->current($enrollment->batch);

        $rate = $this->calculator->forPeriod($enrollment, $period);

        return response()->json([
            'data' => [
                'learner' => $enrollment->learner->displayName(),
                'period' => $period->toArray(),
                'attendance' => $rate->toArray(),
            ],
        ]);
    }
}
