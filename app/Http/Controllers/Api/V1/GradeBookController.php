<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\GradingScheme;
use App\Models\SubmissionStatus;
use App\Services\AssessmentService;
use App\Services\GradeBookService;
use App\Support\Grading\GradeBookCalculator;
use App\Support\Time\PeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GradeBookController
{
    public function __construct(
        private readonly GradeBookService $gradebook,
        private readonly AssessmentService $assessments,
        private readonly GradeBookCalculator $calculator,
        private readonly PeriodService $periods,
    ) {}

    /** Everything the grid needs: assessments, learners, existing cells, and the vocabularies. */
    public function grid(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);
        abort_unless($request->user()->can('grades.view'), 403);

        $batch->load('course');
        $period = $request->filled('period')
            ? $this->periods->forLabel($batch, $request->string('period')->toString())
            : $this->periods->current($batch);

        $grid = $this->gradebook->grid(
            $batch,
            $period->startsLocalDate->toDateString(),
            $period->endsLocalDate->toDateString(),
        );

        return response()->json([
            'data' => $grid + [
                'period' => $period->toArray(),
                'submission_statuses' => SubmissionStatus::query()->orderBy('sort')->get()
                    ->map(fn (SubmissionStatus $s) => [
                        'id' => $s->getKey(),
                        'name' => $s->name,
                        'code' => $s->code,
                        'counts_as_submitted' => $s->counts_as_submitted,
                        'excluded_from_average' => $s->excluded_from_average,
                        'color' => $s->color,
                    ]),
            ],
        ]);
    }

    public function save(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);
        abort_unless($request->user()->can('grades.enter'), 403);

        $validated = $request->validate([
            'cells' => ['required', 'array', 'min:1'],
            'cells.*.assessment_id' => ['required', 'integer'],
            'cells.*.enrollment_id' => ['required', 'integer'],
            'cells.*.submission_status_id' => ['required', 'integer'],
            'cells.*.raw_score' => ['nullable', 'numeric'],
            'cells.*.letter' => ['nullable', 'string', 'max:8'],
            'cells.*.level_code' => ['nullable', 'string', 'max:16'],
            'cells.*.passed' => ['nullable', 'boolean'],
            'cells.*.rubric_scores' => ['nullable', 'array'],
            'cells.*.feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->gradebook->saveGrid($batch, $validated['cells']);

        return response()->json([
            'data' => $result + [
                'message' => sprintf('%d recorded, %d updated.', $result['saved'], $result['updated']),
            ],
        ]);
    }

    /** The period average with its own arithmetic attached. */
    public function average(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless($request->user()->can('view', $enrollment), 403);
        abort_unless($request->user()->can('grades.view'), 403);

        $enrollment->load(['batch.course', 'learner']);

        $period = $request->filled('period')
            ? $this->periods->forLabel($enrollment->batch, $request->string('period')->toString())
            : $this->periods->current($enrollment->batch);

        return response()->json([
            'data' => [
                'learner' => $enrollment->learner->displayName(),
                'period' => $period->toArray(),
                'average' => $this->calculator->forPeriod($enrollment, $period)->toArray(),
            ],
        ]);
    }

    public function storeAssessment(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('assessments.manage'), 403);
        abort_unless($request->user()->can('view', $batch), 403);

        $validated = $request->validate([
            'assessment_type_id' => ['required', 'integer', 'exists:assessment_types,id'],
            'grading_scheme_id' => ['required', 'integer', 'exists:grading_schemes,id'],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_local_date' => ['nullable', 'date_format:Y-m-d'],
            'due_local_date' => ['nullable', 'date_format:Y-m-d'],
            'max_points' => ['nullable', 'numeric', 'min:0.01'],
            'publish' => ['boolean'],
            'criteria' => ['nullable', 'array'],
            'criteria.*.name' => ['required_with:criteria', 'string', 'max:190'],
            'criteria.*.max_points' => ['required_with:criteria', 'numeric', 'min:0.01'],
            'criteria.*.descriptor' => ['nullable', 'string', 'max:500'],
        ]);

        $assessment = $this->assessments->create(
            $batch,
            collect($validated)->except(['criteria', 'publish'])->all(),
            $validated['criteria'] ?? [],
        );

        if ($request->boolean('publish')) {
            $assessment = $this->assessments->publish($assessment);
        }

        return response()->json([
            'data' => [
                'id' => $assessment->getKey(),
                'number' => $assessment->number,
                'title' => $assessment->title,
                'max_points' => $assessment->max_points,
                'is_published' => $assessment->is_published,
            ],
        ], 201);
    }

    public function ungraded(Request $request, Assessment $assessment): JsonResponse
    {
        abort_unless($request->user()->can('grades.view'), 403);
        abort_unless($request->user()->can('view', $assessment->batch), 403);

        return response()->json([
            'data' => $this->gradebook->ungradedFor($assessment)->map(fn (Enrollment $e) => [
                'enrollment_id' => $e->getKey(),
                'name' => $e->learner->displayName(),
            ])->values(),
        ]);
    }

    /** The vocabularies a grid needs to render its column headers and status options. */
    public function vocabularies(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('grades.view'), 403);

        return response()->json([
            'data' => [
                'assessment_types' => AssessmentType::query()->where('is_active', true)->orderBy('sort')->get(),
                'grading_schemes' => GradingScheme::query()->where('is_active', true)->get()
                    ->map(fn (GradingScheme $s) => [
                        'id' => $s->getKey(),
                        'name' => $s->name,
                        'kind' => $s->kind->value,
                        'label' => $s->kind->label(),
                        'needs_maximum' => $s->kind->hasPerAssessmentMaximum(),
                        'config' => $s->config,
                    ]),
                'submission_statuses' => SubmissionStatus::query()->orderBy('sort')->get(),
            ],
        ]);
    }
}
