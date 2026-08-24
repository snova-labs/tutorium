<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

/**
 * An ordered ladder — CEFR A1 to C2, or any scale a tenant defines.
 *
 * Position on the ladder becomes the normalised value, so a language school's levels can appear
 * in the same average as a points quiz without either being distorted.
 *
 * config: {"ladder": ["A1", "A2", "B1", "B2", "C1", "C2"]}
 */
final class LevelStrategy implements GradingStrategy
{
    public function normalize(Grade $grade, Assessment $assessment): ?float
    {
        $ladder = $this->ladder($assessment);
        $index = $this->indexOf($ladder, (string) $grade->level_code);

        if ($index === null || count($ladder) < 2) {
            return null;
        }

        return round(($index / (count($ladder) - 1)) * 100, 2);
    }

    public function validate(array $input, Assessment $assessment): void
    {
        $level = $input['level_code'] ?? null;

        if ($level === null) {
            return;
        }

        $ladder = $this->ladder($assessment);

        if ($this->indexOf($ladder, (string) $level) === null) {
            throw ValidationException::withMessages([
                'level_code' => "\"{$level}\" is not on this scale. Use one of: ".implode(', ', $ladder).'.',
            ]);
        }
    }

    public function display(Grade $grade, Assessment $assessment): string
    {
        return $grade->level_code ?? '—';
    }

    public function apply(Grade $grade, Assessment $assessment, array $input): void
    {
        $grade->level_code = $input['level_code'] ?? null;
        $grade->raw_score = null;
        $grade->letter = null;
        $grade->passed = null;
    }

    /** @return array<int, string> */
    private function ladder(Assessment $assessment): array
    {
        return $assessment->gradingScheme->config['ladder'] ?? [];
    }

    /** @param array<int, string> $ladder */
    private function indexOf(array $ladder, string $code): ?int
    {
        foreach ($ladder as $i => $step) {
            if (strcasecmp($step, $code) === 0) {
                return $i;
            }
        }

        return null;
    }
}
