<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

/** A score out of a maximum the assessment sets — never assumed to be 100. */
final class PointsStrategy implements GradingStrategy
{
    public function normalize(Grade $grade, Assessment $assessment): ?float
    {
        $max = (float) ($assessment->max_points ?? 0);

        if ($max <= 0 || $grade->raw_score === null) {
            return null;
        }

        return round(((float) $grade->raw_score / $max) * 100, 2);
    }

    public function validate(array $input, Assessment $assessment): void
    {
        $score = $input['raw_score'] ?? null;

        if ($score === null) {
            return;
        }

        $max = (float) ($assessment->max_points ?? 0);

        if ($score < 0) {
            throw ValidationException::withMessages(['raw_score' => 'A score cannot be negative.']);
        }

        // Deliberately allows exceeding the maximum only when it is obviously a mistake rather
        // than a bonus mark — a hard cap would block legitimate extra credit.
        if ($score > $max * 1.5) {
            throw ValidationException::withMessages([
                'raw_score' => "That is well above the maximum of {$max}. Check the number, or raise the maximum.",
            ]);
        }
    }

    public function display(Grade $grade, Assessment $assessment): string
    {
        if ($grade->raw_score === null) {
            return '—';
        }

        return rtrim(rtrim((string) $grade->raw_score, '0'), '.').' / '.rtrim(rtrim((string) $assessment->max_points, '0'), '.');
    }

    public function apply(Grade $grade, Assessment $assessment, array $input): void
    {
        $grade->raw_score = $input['raw_score'] ?? null;
        $grade->letter = null;
        $grade->level_code = null;
        $grade->passed = null;
    }
}
