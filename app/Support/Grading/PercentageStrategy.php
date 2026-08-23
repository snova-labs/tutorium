<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

final class PercentageStrategy implements GradingStrategy
{
    public function normalize(Grade $grade, Assessment $assessment): ?float
    {
        return $grade->raw_score === null ? null : round((float) $grade->raw_score, 2);
    }

    public function validate(array $input, Assessment $assessment): void
    {
        $score = $input['raw_score'] ?? null;

        if ($score !== null && ($score < 0 || $score > 100)) {
            throw ValidationException::withMessages([
                'raw_score' => 'A percentage must be between 0 and 100.',
            ]);
        }
    }

    public function display(Grade $grade, Assessment $assessment): string
    {
        return $grade->raw_score === null ? '—' : rtrim(rtrim((string) $grade->raw_score, '0'), '.').'%';
    }

    public function apply(Grade $grade, Assessment $assessment, array $input): void
    {
        $grade->raw_score = $input['raw_score'] ?? null;
        $grade->letter = null;
        $grade->level_code = null;
        $grade->passed = null;
    }
}
