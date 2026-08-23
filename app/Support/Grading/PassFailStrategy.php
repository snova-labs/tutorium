<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

/**
 * Two outcomes.
 *
 * The normalised values are configurable rather than hard-wired to 100 and 0: a certification
 * body that treats a pass as competence-demonstrated may not want it pulling an average upward
 * like a perfect score would.
 */
final class PassFailStrategy implements GradingStrategy
{
    public function normalize(Grade $grade, Assessment $assessment): ?float
    {
        if ($grade->passed === null) {
            return null;
        }

        $config = $assessment->gradingScheme->config ?? [];

        return $grade->passed
            ? (float) ($config['pass_value'] ?? 100)
            : (float) ($config['fail_value'] ?? 0);
    }

    public function validate(array $input, Assessment $assessment): void
    {
        if (array_key_exists('passed', $input) && $input['passed'] !== null && ! is_bool($input['passed'])) {
            throw ValidationException::withMessages(['passed' => 'Record a pass or a fail.']);
        }
    }

    public function display(Grade $grade, Assessment $assessment): string
    {
        return match ($grade->passed) {
            true => 'Pass',
            false => 'Fail',
            default => '—',
        };
    }

    public function apply(Grade $grade, Assessment $assessment, array $input): void
    {
        $grade->passed = $input['passed'] ?? null;
        $grade->raw_score = null;
        $grade->letter = null;
        $grade->level_code = null;
    }
}
