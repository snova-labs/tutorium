<?php

declare(strict_types=1);

namespace App\Livewire\Gradebook;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\SubmissionStatus;
use App\Services\GradeBookService;
use App\Support\Grading\GradeBookCalculator;
use App\Support\Time\PeriodService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The gradebook.
 *
 * Points, rubrics and pass/fail in one grid, which is only possible because
 * every scheme normalises to a comparable percentage underneath. The screen's
 * job is to let a teacher enter results in the terms the work was set in, and
 * then show the period average with its own arithmetic attached — a number a
 * parent will read should be one a teacher can defend.
 */
final class Grid extends Component
{
    #[Locked]
    public int $batchId;

    #[Url(as: 'period')]
    public string $periodLabel = '';

    /** @var array<string, mixed> keyed "enrollmentId:assessmentId" */
    public array $cells = [];

    /** @var array<string, true> keyed "enrollmentId:assessmentId" */
    public array $dirty = [];

    public ?string $error = null;

    public bool $saved = false;

    public ?int $explaining = null;

    public function mount(Batch $batch): void
    {
        $this->authorize('view', $batch);
        abort_unless(auth()->user()->can('grades.view'), 403);

        $this->batchId = $batch->getKey();
        $this->periodLabel = $this->periodLabel ?: app(PeriodService::class)
            ->current($batch->load('course'))->label;

        $this->hydrateCells();
    }

    #[Computed(persist: true)]
    public function batch(): Batch
    {
        return Batch::query()->with('course')->findOrFail($this->batchId);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function grid(): array
    {
        $period = app(PeriodService::class)->forLabel($this->batch(), $this->periodLabel);

        return app(GradeBookService::class)->grid(
            $this->batch(),
            $period->startsLocalDate->toDateString(),
            $period->endsLocalDate->toDateString(),
        );
    }

    /** @return Collection<int, SubmissionStatus> */
    #[Computed(persist: true)]
    public function submissionStatuses(): Collection
    {
        return SubmissionStatus::query()->orderBy('sort')->get();
    }

    /**
     * The period average for one learner, with the breakdown that produced it.
     *
     * @return array<string, mixed>
     */
    public function average(int $enrollmentId): array
    {
        $enrollment = Enrollment::query()->with('batch.course')->findOrFail($enrollmentId);
        $period = app(PeriodService::class)->forLabel($this->batch(), $this->periodLabel);

        return app(GradeBookCalculator::class)->forPeriod($enrollment, $period)->toArray();
    }

    public function explain(int $enrollmentId): void
    {
        // Toggling, so the same click closes it. The panel is the point of the
        // screen, not a modal to be dismissed.
        $this->explaining = $this->explaining === $enrollmentId ? null : $enrollmentId;
    }

    public function updatedCells(mixed $value, string $key): void
    {
        // "enrollmentId:assessmentId:field"
        $this->dirty[implode(':', array_slice(explode('.', $key), 0, 2))] = true;
        $this->saved = false;
    }

    public function save(): void
    {
        $this->error = null;
        abort_unless(auth()->user()->can('grades.enter'), 403);

        $payload = [];

        foreach (array_keys($this->dirty) as $key) {
            [$enrollmentId, $assessmentId] = explode(':', $key);
            $cell = $this->cells[$key] ?? null;

            if ($cell === null || ($cell['submission_status_id'] ?? null) === null) {
                continue;
            }

            $payload[] = array_filter([
                'enrollment_id' => (int) $enrollmentId,
                'assessment_id' => (int) $assessmentId,
                'submission_status_id' => (int) $cell['submission_status_id'],
                'raw_score' => $cell['raw_score'] ?? null,
                'letter' => $cell['letter'] ?? null,
                'level_code' => $cell['level_code'] ?? null,
                'passed' => $cell['passed'] ?? null,
                'feedback' => $cell['feedback'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($payload === []) {
            $this->error = 'Nothing has changed yet.';

            return;
        }

        try {
            // One transaction for the whole grid: a teacher entering forty results
            // should never have to work out which half committed.
            app(GradeBookService::class)->saveGrid($this->batch(), $payload);
        } catch (ValidationException $e) {
            // The scheme's own message, which names the criterion or the scale
            // rather than reporting a type error.
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }

        $this->dirty = [];
        $this->saved = true;
        unset($this->grid);
    }

    private function hydrateCells(): void
    {
        foreach ($this->grid()['rows'] as $row) {
            foreach ($row['cells'] as $assessmentId => $cell) {
                if ($cell === null) {
                    continue;
                }

                $this->cells[$row['enrollment_id'].':'.$assessmentId] = [
                    'submission_status_id' => $cell['submission_status_id'],
                    'raw_score' => $cell['raw_score'],
                    'letter' => $cell['letter'],
                    'level_code' => $cell['level_code'],
                    'passed' => $cell['passed'],
                    'feedback' => $cell['feedback'],
                ];
            }
        }
    }

    public function render(): View
    {
        return view('livewire.gradebook.grid')->layout('layouts.app');
    }
}
