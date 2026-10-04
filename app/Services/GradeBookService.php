<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Assessment;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SubmissionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entering and saving grades.
 *
 * The whole grid saves in one transaction. A teacher entering forty results should not have to
 * work out which half committed when something went wrong mid-request (FR-GRD-5).
 */
final class GradeBookService
{
    /**
     * The grid a gradebook screen renders: enrolled learners across published assessments.
     *
     * @return array<string, mixed>
     */
    public function grid(Batch $batch, ?string $from = null, ?string $to = null): array
    {
        $assessments = Assessment::query()
            ->with(['assessmentType', 'gradingScheme', 'rubricCriteria'])
            ->where('batch_id', $batch->getKey())
            ->published()
            ->when($from !== null, fn ($q) => $q->where('due_local_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('due_local_date', '<=', $to))
            ->orderBy('due_local_date')
            ->get();

        $enrollments = Enrollment::query()
            ->with('learner')
            ->where('batch_id', $batch->getKey())
            ->active()
            ->get()
            ->sortBy(fn (Enrollment $e) => $e->learner->sort_name ?: $e->learner->legal_name)
            ->values();

        $grades = Grade::query()
            ->with('submissionStatus')
            ->whereIn('assessment_id', $assessments->modelKeys())
            ->whereIn('enrollment_id', $enrollments->modelKeys())
            ->get()
            ->groupBy('enrollment_id');

        return [
            'assessments' => $assessments->map(fn (Assessment $a) => [
                'id' => $a->getKey(),
                'title' => $a->title,
                'type' => $a->assessmentType->name,
                'scheme' => [
                    'kind' => $a->gradingScheme->kind->value,
                    'label' => $a->gradingScheme->kind->label(),
                    'max_points' => $a->max_points,
                    'config' => $a->gradingScheme->config,
                ],
                'due_local_date' => $a->due_local_date?->toDateString(),
                'criteria' => $a->rubricCriteria->map(fn ($c) => [
                    'id' => $c->getKey(), 'name' => $c->name, 'max_points' => $c->max_points,
                ])->all(),
            ])->values(),

            'rows' => $enrollments->map(function (Enrollment $enrollment) use ($grades, $assessments): array {
                $own = ($grades[$enrollment->getKey()] ?? collect())->keyBy('assessment_id');

                return [
                    'enrollment_id' => $enrollment->getKey(),
                    'number' => $enrollment->learner->number,
                    'name' => $enrollment->learner->displayName(),
                    'cells' => $assessments->mapWithKeys(function (Assessment $a) use ($own): array {
                        $grade = $own->get($a->getKey());

                        return [$a->getKey() => $grade === null ? null : [
                            'grade_id' => $grade->getKey(),
                            'submission_status_id' => $grade->submission_status_id,
                            'raw_score' => $grade->raw_score,
                            'letter' => $grade->letter,
                            'level_code' => $grade->level_code,
                            'passed' => $grade->passed,
                            'normalized_pct' => $grade->normalized_pct,
                            'feedback' => $grade->feedback,
                        ]];
                    })->all(),
                ];
            })->values(),
        ];
    }

    /**
     * Save a set of cells.
     *
     * @param array<int, array<string, mixed>> $cells
     * @return array{saved: int, updated: int}
     */
    public function saveGrid(Batch $batch, array $cells): array
    {
        $assessments = Assessment::query()
            ->with(['gradingScheme', 'rubricCriteria'])
            ->where('batch_id', $batch->getKey())
            ->get()
            ->keyBy('id');

        $enrollmentIds = Enrollment::query()->where('batch_id', $batch->getKey())->pluck('id')->flip();
        $statuses = SubmissionStatus::query()->get()->keyBy('id');

        return DB::transaction(function () use ($cells, $assessments, $enrollmentIds, $statuses): array {
            $saved = 0;
            $updated = 0;

            foreach ($cells as $cell) {
                $assessment = $assessments->get($cell['assessment_id'] ?? 0);

                if ($assessment === null) {
                    throw ValidationException::withMessages([
                        'cells' => 'One of these assessments does not belong to this batch.',
                    ]);
                }

                if (! $enrollmentIds->has($cell['enrollment_id'] ?? 0)) {
                    throw ValidationException::withMessages([
                        'cells' => 'One of these learners is not enrolled in this batch.',
                    ]);
                }

                $status = $statuses->get($cell['submission_status_id'] ?? 0);

                if ($status === null) {
                    throw ValidationException::withMessages(['cells' => 'Unknown submission status.']);
                }

                // The scheme checks its own input, so a rubric score above its criterion maximum
                // and a letter outside the scale both fail here rather than being stored.
                $strategy = $assessment->gradingScheme->strategy();
                $strategy->validate($cell, $assessment);

                $grade = Grade::query()
                    ->where('assessment_id', $assessment->getKey())
                    ->where('enrollment_id', $cell['enrollment_id'])
                    ->first();

                $isNew = $grade === null;

                $grade ??= new Grade([
                    'assessment_id' => $assessment->getKey(),
                    'enrollment_id' => $cell['enrollment_id'],
                ]);

                $grade->submission_status_id = $status->getKey();
                $grade->feedback = $cell['feedback'] ?? $grade->feedback;
                $grade->graded_by = Auth::id();
                $grade->graded_at = now()->toImmutable();

                $strategy->apply($grade, $assessment, $cell);

                // Exempt work stores no comparable value at all — it leaves the denominator
                // rather than sitting in it as a zero.
                $grade->normalized_pct = $status->excluded_from_average
                    ? null
                    : $strategy->normalize($grade, $assessment);

                // Missing work is a zero, because not handing something in is a fact about the
                // month rather than an absence of information.
                if (! $status->counts_as_submitted && ! $status->excluded_from_average) {
                    $grade->normalized_pct = 0.0;
                }

                $grade->save();

                $this->syncRubricScores($grade, $cell);

                $isNew ? $saved++ : $updated++;
            }

            return ['saved' => $saved, 'updated' => $updated];
        });
    }

    /** @param array<string, mixed> $cell */
    private function syncRubricScores(Grade $grade, array $cell): void
    {
        if (! isset($cell['rubric_scores']) || ! is_array($cell['rubric_scores'])) {
            return;
        }

        foreach ($cell['rubric_scores'] as $criterionId => $points) {
            $grade->rubricScores()->updateOrCreate(
                ['rubric_criterion_id' => (int) $criterionId],
                ['points' => (float) $points],
            );
        }
    }

    /**
     * Every learner still missing a grade for an assessment — the "who have I not marked" list.
     *
     * @return Collection<int, Enrollment>
     */
    public function ungradedFor(Assessment $assessment): Collection
    {
        $graded = Grade::query()->where('assessment_id', $assessment->getKey())->pluck('enrollment_id');

        return Enrollment::query()
            ->with('learner')
            ->where('batch_id', $assessment->batch_id)
            ->active()
            ->whereNotIn('id', $graded)
            ->get();
    }
}
