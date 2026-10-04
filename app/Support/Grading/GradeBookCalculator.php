<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\TypeWeight;
use App\Support\Time\PeriodBoundary;
use Illuminate\Support\Collection;

/**
 * The period average.
 *
 * Two rules do all the work, and both exist because the alternative is a number that misrepresents
 * a learner:
 *
 *  - **Missing work scores zero and stays in the denominator.** It is a fact about the month.
 *  - **Exempt work leaves the denominator entirely, and the remaining weights are rescaled.**
 *    A learner excused from the project is not a learner who failed it.
 *
 * Where a course defines type weights, each type is averaged first and then weighted, so five
 * homeworks do not outvote one project simply by being more numerous.
 */
final class GradeBookCalculator
{
    public function forPeriod(Enrollment $enrollment, PeriodBoundary $period): PeriodAverage
    {
        $assessments = $this->assessmentsIn($enrollment, $period);

        if ($assessments->isEmpty()) {
            return new PeriodAverage(null, false, 0.0, [], 0, 0, 0, 0);
        }

        $grades = Grade::query()
            ->with('submissionStatus')
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('assessment_id', $assessments->modelKeys())
            ->get()
            ->keyBy('assessment_id');

        $weights = $this->weightsFor($enrollment);

        $byType = [];
        $graded = 0;
        $missing = 0;
        $excluded = 0;
        $ungraded = 0;

        foreach ($assessments as $assessment) {
            $typeId = (int) $assessment->assessment_type_id;
            $byType[$typeId] ??= ['name' => $assessment->assessmentType->name, 'values' => [], 'excluded' => 0];

            $grade = $grades->get($assessment->getKey());

            if ($grade === null) {
                $ungraded++;

                continue;
            }

            if ($grade->submissionStatus->excluded_from_average) {
                $excluded++;
                $byType[$typeId]['excluded']++;

                continue;
            }

            if (! $grade->submissionStatus->counts_as_submitted) {
                // Missing work is a zero, not an absence of information. Counted by its status,
                // because the grade book may already have stored the zero as a score.
                $byType[$typeId]['values'][] = 0.0;
                $missing++;

                continue;
            }

            if ($grade->normalized_pct === null) {
                $ungraded++;

                continue;
            }

            $byType[$typeId]['values'][] = (float) $grade->normalized_pct;
            $graded++;
        }

        return $this->combine($byType, $weights, $graded, $missing, $excluded, $ungraded);
    }

    /**
     * @param array<int, array{name: string, values: array<int, float>, excluded: int}> $byType
     * @param array<int, float> $weights
     */
    private function combine(array $byType, array $weights, int $graded, int $missing, int $excluded, int $ungraded): PeriodAverage
    {
        $breakdown = [];
        $weightedSum = 0.0;
        $weightsUsed = 0.0;
        $flat = [];
        $anyWeighted = false;

        foreach ($byType as $typeId => $type) {
            $counted = count($type['values']);

            if ($counted === 0) {
                // A type with nothing gradable this period contributes nothing and, crucially,
                // takes its weight out of the total rather than counting as a zero.
                $breakdown[] = [
                    'type' => $type['name'], 'mean' => 0.0, 'weight' => $weights[$typeId] ?? null,
                    'contribution' => null, 'counted' => 0, 'excluded' => $type['excluded'],
                ];

                continue;
            }

            $mean = round(array_sum($type['values']) / $counted, 2);
            $weight = $weights[$typeId] ?? null;
            $flat = array_merge($flat, $type['values']);

            if ($weight !== null) {
                $anyWeighted = true;
                $weightedSum += $mean * $weight;
                $weightsUsed += $weight;
            }

            $breakdown[] = [
                'type' => $type['name'],
                'mean' => $mean,
                'weight' => $weight,
                'contribution' => $weight === null ? null : round($mean * $weight / 100, 2),
                'counted' => $counted,
                'excluded' => $type['excluded'],
            ];
        }

        if ($flat === []) {
            return new PeriodAverage(null, false, 0.0, $breakdown, $graded, $missing, $excluded, $ungraded);
        }

        // Weighted where the course says so and at least one weighted type has results; otherwise
        // a plain mean, which is the honest fallback rather than a silent zero.
        if ($anyWeighted && $weightsUsed > 0) {
            return new PeriodAverage(
                percentage: round($weightedSum / $weightsUsed, 1),
                isWeighted: true,
                weightsUsed: round($weightsUsed, 2),
                breakdown: $breakdown,
                graded: $graded,
                missing: $missing,
                excluded: $excluded,
                ungraded: $ungraded,
            );
        }

        return new PeriodAverage(
            percentage: round(array_sum($flat) / count($flat), 1),
            isWeighted: false,
            weightsUsed: 0.0,
            breakdown: $breakdown,
            graded: $graded,
            missing: $missing,
            excluded: $excluded,
            ungraded: $ungraded,
        );
    }

    /** @return Collection<int, Assessment> */
    private function assessmentsIn(Enrollment $enrollment, PeriodBoundary $period): Collection
    {
        return Assessment::query()
            ->with('assessmentType')
            ->where('batch_id', $enrollment->batch_id)
            ->where('is_published', true)
            ->whereBetween('due_local_date', [
                $period->startsLocalDate->toDateString(),
                $period->endsLocalDate->toDateString(),
            ])
            // Work due before a learner joined is not theirs to answer for.
            ->when(
                $enrollment->enrolled_on !== null,
                fn ($q) => $q->where('due_local_date', '>=', $enrollment->enrolled_on->toDateString()),
            )
            ->get();
    }

    /** @return array<int, float> assessment type id => weight percentage */
    private function weightsFor(Enrollment $enrollment): array
    {
        return TypeWeight::query()
            ->where('course_id', $enrollment->batch->course_id)
            ->pluck('weight_pct', 'assessment_type_id')
            ->map(fn ($w) => (float) $w)
            ->all();
    }
}
