<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\TeacherNote;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendanceRate;
use App\Support\Grading\GradeBookCalculator;
use App\Support\Grading\PeriodAverage;
use App\Support\Time\PeriodBoundary;

/**
 * Assembles everything a report says, once.
 *
 * Every figure here comes from the same calculators the dashboards use, so a parent's report and
 * a teacher's screen can never disagree about the same month.
 */
final class ReportDataBuilder
{
    public function __construct(
        private readonly AttendanceCalculator $attendance,
        private readonly GradeBookCalculator $grades,
    ) {}

    public function build(Enrollment $enrollment, PeriodBoundary $period): ReportSnapshot
    {
        $enrollment->loadMissing(['learner', 'batch.course.brand']);

        $attendance = $this->attendance->forPeriod($enrollment, $period);
        $average = $this->grades->forPeriod($enrollment, $period);
        $assessments = $this->assessmentLines($enrollment, $period);

        return new ReportSnapshot(
            learnerName: $enrollment->learner->displayName(),
            batchName: $enrollment->batch->name,
            courseName: $enrollment->batch->course->name,
            brandName: $enrollment->batch->course->brand->name,
            periodLabel: $period->label,
            periodStart: $period->startsLocalDate->toDateString(),
            periodEnd: $period->endsLocalDate->toDateString(),
            attendance: $attendance->toArray(),
            average: $average->toArray(),
            assessments: $assessments,
            highlights: $this->highlights($attendance, $average, $assessments),
            notes: $this->notes($enrollment, $period),
            engagementStars: $this->engagement($attendance, $average),
            generatedAtUtc: now()->toIso8601String(),
        );
    }

    /** Whether the period has gaps a reader would be misled by. */
    public function readiness(Enrollment $enrollment, PeriodBoundary $period): ReportReadiness
    {
        $gaps = [];

        $attendance = $this->attendance->forPeriod($enrollment, $period);
        $average = $this->grades->forPeriod($enrollment, $period);

        if (! $attendance->isComplete()) {
            $gaps[] = sprintf(
                '%d %s not marked for attendance.',
                $attendance->unmarked,
                $attendance->unmarked === 1 ? 'session is' : 'sessions are',
            );
        }

        if (! $average->isComplete()) {
            $gaps[] = sprintf(
                '%d %s not graded.',
                $average->ungraded,
                $average->ungraded === 1 ? 'assessment is' : 'assessments are',
            );
        }

        return $gaps === [] ? ReportReadiness::ready() : ReportReadiness::incomplete($gaps);
    }

    /** @return array<int, array<string, mixed>> */
    private function assessmentLines(Enrollment $enrollment, PeriodBoundary $period): array
    {
        $assessments = Assessment::query()
            ->with(['assessmentType', 'gradingScheme', 'rubricCriteria'])
            ->where('batch_id', $enrollment->batch_id)
            ->published()
            ->whereBetween('due_local_date', [
                $period->startsLocalDate->toDateString(),
                $period->endsLocalDate->toDateString(),
            ])
            ->orderBy('due_local_date')
            ->get();

        $grades = Grade::query()
            ->with('submissionStatus')
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('assessment_id', $assessments->modelKeys())
            ->get()
            ->keyBy('assessment_id');

        return $assessments->map(function (Assessment $assessment) use ($grades): array {
            // The assessment is already loaded; hand it to the grade rather than letting the grade
            // fetch it again, once per learner per assessment.
            $grade = $grades->get($assessment->getKey())?->setRelation('assessment', $assessment);

            return [
                'title' => $assessment->title,
                'type' => $assessment->assessmentType->name,
                'due' => $assessment->due_local_date?->toDateString(),
                // Displayed in the scheme's own terms — "17 / 20", "B1", "Pass" — because that is
                // what the learner was told when the work was set.
                'result' => $grade === null ? null : $assessment
                    ->gradingScheme->strategy()->display($grade, $assessment),
                'status' => $grade?->submissionStatus->name,
                'normalized_pct' => $grade?->normalized_pct === null ? null : (float) $grade->normalized_pct,
                'feedback' => $grade?->feedback,
            ];
        })->values()->all();
    }

    /**
     * @param array<int, array<string, mixed>> $assessments
     * @return array<int, string>
     */
    private function highlights(
        AttendanceRate $attendance,
        PeriodAverage $average,
        array $assessments,
    ): array {
        $highlights = [];

        if ($attendance->percentage() !== null && $attendance->percentage() >= 95) {
            $highlights[] = 'Attended almost every session this period.';
        }

        $best = collect($assessments)->whereNotNull('normalized_pct')->sortByDesc('normalized_pct')->first();

        if ($best !== null && $best['normalized_pct'] >= 80) {
            $highlights[] = sprintf('Did particularly well on %s (%s).', $best['title'], $best['result']);
        }

        $excused = collect($assessments)->where('status', 'Exempt')->count();

        if ($excused > 0) {
            // Said plainly, because a parent seeing a gap deserves to know it was agreed rather
            // than missed.
            $highlights[] = sprintf(
                '%d %s excused this period and %s not counted in the average.',
                $excused,
                $excused === 1 ? 'piece of work was' : 'pieces of work were',
                $excused === 1 ? 'is' : 'are',
            );
        }

        if ($average->missing > 0) {
            $highlights[] = sprintf(
                '%d %s not handed in.',
                $average->missing,
                $average->missing === 1 ? 'piece of work was' : 'pieces of work were',
            );
        }

        return $highlights;
    }

    /** @return array<int, array{category: string, body: string}> */
    private function notes(Enrollment $enrollment, PeriodBoundary $period): array
    {
        return TeacherNote::query()
            ->with('category')
            ->where('enrollment_id', $enrollment->getKey())
            ->visibleOnReports()
            ->whereHas('period', fn ($q) => $q->where('label', $period->label))
            ->get()
            ->map(fn (TeacherNote $note) => [
                'category' => $note->category->name,
                'body' => $note->body,
            ])
            ->values()
            ->all();
    }

    /**
     * A one-to-five indicator combining turning up and handing work in.
     *
     * Deliberately coarse. A number to one decimal place would invite an argument about a
     * judgement that is not that precise.
     */
    private function engagement(
        AttendanceRate $attendance,
        PeriodAverage $average,
    ): ?int {
        $attendancePct = $attendance->percentage();

        if ($attendancePct === null && $average->percentage === null) {
            return null;
        }

        $parts = array_filter([$attendancePct, $average->percentage], static fn ($v) => $v !== null);
        $combined = array_sum($parts) / count($parts);

        return max(1, min(5, (int) ceil($combined / 20)));
    }
}
