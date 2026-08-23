<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

/**
 * Named criteria with individual maxima.
 *
 * The per-criterion scores are the record; `raw_score` is their sum, kept so that the grid and
 * the averages do not have to reassemble a rubric to show a total.
 */
final class RubricStrategy implements GradingStrategy
{
    public function normalize(Grade $grade, Assessment $assessment): ?float
    {
        $max = (float) $assessment->rubricCriteria->sum('max_points');

        if ($max <= 0 || $grade->raw_score === null) {
            return null;
        }

        return round(((float) $grade->raw_score / $max) * 100, 2);
    }

    public function validate(array $input, Assessment $assessment): void
    {
        $scores = $input['rubric_scores'] ?? null;

        if ($scores === null) {
            return;
        }

        $criteria = $assessment->rubricCriteria->keyBy('id');

        foreach ($scores as $criterionId => $points) {
            $criterion = $criteria->get((int) $criterionId);

            if ($criterion === null) {
                throw ValidationException::withMessages([
                    'rubric_scores' => 'One of these criteria does not belong to this assessment.',
                ]);
            }

            if ($points < 0 || $points > (float) $criterion->max_points) {
                throw ValidationException::withMessages([
                    'rubric_scores' => sprintf(
                        '"%s" is scored out of %s.',
                        $criterion->name,
                        rtrim(rtrim((string) $criterion->max_points, '0'), '.'),
                    ),
                ]);
            }
        }
    }

    public function display(Grade $grade, Assessment $assessment): string
    {
        if ($grade->raw_score === null) {
            return '—';
        }

        $max = $assessment->rubricCriteria->sum('max_points');

        return rtrim(rtrim((string) $grade->raw_score, '0'), '.').' / '.rtrim(rtrim((string) $max, '0'), '.');
    }

    public function apply(Grade $grade, Assessment $assessment, array $input): void
    {
        $scores = $input['rubric_scores'] ?? null;

        $grade->raw_score = $scores === null ? null : array_sum(array_map('floatval', $scores));
        $grade->letter = null;
        $grade->level_code = null;
        $grade->passed = null;
    }
}
