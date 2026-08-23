<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GradingSchemeKind;
use App\Models\Assessment;
use App\Models\Batch;
use App\Models\GradingScheme;
use App\Support\Sequences\IdSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssessmentService
{
    public function __construct(private readonly IdSequenceService $sequences) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{name: string, max_points: float, descriptor?: string}>  $criteria
     */
    public function create(Batch $batch, array $attributes, array $criteria = []): Assessment
    {
        $scheme = GradingScheme::query()->findOrFail($attributes['grading_scheme_id']);

        $this->guardSchemeInputs($scheme, $attributes, $criteria);

        return DB::transaction(function () use ($batch, $attributes, $criteria, $scheme): Assessment {
            $assessment = new Assessment($attributes);
            $assessment->batch()->associate($batch);
            $assessment->number = $this->sequences->next('assessment');

            // The due date is a local promise. The instant is derived from it in the batch's own
            // timezone, so "due Friday" means Friday where the class is, not where the server is.
            if (! empty($attributes['due_local_date'])) {
                $assessment->due_at_utc = CarbonImmutable::parse(
                    $attributes['due_local_date'].' 23:59:59',
                    $batch->timezone,
                )->utc();
            }

            $assessment->save();

            if ($scheme->kind === GradingSchemeKind::Rubric) {
                foreach ($criteria as $i => $criterion) {
                    $assessment->rubricCriteria()->create([
                        'name' => $criterion['name'],
                        'descriptor' => $criterion['descriptor'] ?? null,
                        'max_points' => $criterion['max_points'],
                        'sort' => $i,
                    ]);
                }

                // Kept in step so a grid can show a total without reassembling the rubric.
                $assessment->update(['max_points' => $assessment->rubricCriteria()->sum('max_points')]);
            }

            return $assessment->refresh();
        });
    }

    public function publish(Assessment $assessment): Assessment
    {
        if ($assessment->gradingScheme->kind === GradingSchemeKind::Rubric
            && $assessment->rubricCriteria()->doesntExist()) {
            throw ValidationException::withMessages([
                'assessment' => 'Add at least one rubric criterion before publishing this.',
            ]);
        }

        $assessment->update(['is_published' => true]);

        return $assessment->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(Assessment $assessment, array $attributes): Assessment
    {
        // Changing the scheme after grading would leave every existing result meaning something
        // it was not entered to mean.
        if (isset($attributes['grading_scheme_id'])
            && (int) $attributes['grading_scheme_id'] !== (int) $assessment->grading_scheme_id
            && $assessment->grades()->exists()) {
            throw ValidationException::withMessages([
                'grading_scheme_id' => 'This assessment already has grades. Changing how it is scored would '
                    .'reinterpret every one of them. Create a new assessment instead.',
            ]);
        }

        return DB::transaction(function () use ($assessment, $attributes): Assessment {
            $assessment->update($attributes);

            return $assessment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $criteria
     */
    private function guardSchemeInputs(GradingScheme $scheme, array $attributes, array $criteria): void
    {
        if ($scheme->kind === GradingSchemeKind::Points && empty($attributes['max_points'])) {
            throw ValidationException::withMessages([
                'max_points' => 'A points assessment needs a maximum. It is not assumed to be 100.',
            ]);
        }

        if ($scheme->kind === GradingSchemeKind::Rubric && $criteria === []) {
            throw ValidationException::withMessages([
                'criteria' => 'A rubric needs at least one criterion.',
            ]);
        }
    }
}
