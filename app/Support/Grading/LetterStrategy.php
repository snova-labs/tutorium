<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-defined letter bands.
 *
 * Handles A–F, A*–U and German 1–6 with the same code, because a band carries its own normalised
 * value. That is what makes a scale where **1 is best** work without a single special case
 * anywhere downstream: the band labelled "1" simply declares a value of 100.
 *
 * config: {"bands": [{"label": "A", "value": 92.5, "min": 85, "max": 100}, ...]}
 */
final class LetterStrategy implements GradingStrategy
{
    public function normalize(Grade $grade, Assessment $assessment): ?float
    {
        $band = $this->band($assessment, (string) $grade->letter);

        if ($band === null) {
            return null;
        }

        // An explicit value wins; otherwise the midpoint of the band is the honest reading of
        // "somewhere in here".
        if (isset($band['value'])) {
            return round((float) $band['value'], 2);
        }

        if (isset($band['min'], $band['max'])) {
            return round(((float) $band['min'] + (float) $band['max']) / 2, 2);
        }

        return null;
    }

    public function validate(array $input, Assessment $assessment): void
    {
        $letter = $input['letter'] ?? null;

        if ($letter === null) {
            return;
        }

        if ($this->band($assessment, (string) $letter) === null) {
            $labels = implode(', ', array_column($this->bands($assessment), 'label'));

            throw ValidationException::withMessages([
                'letter' => "\"{$letter}\" is not one of this scale's grades. Use one of: {$labels}.",
            ]);
        }
    }

    public function display(Grade $grade, Assessment $assessment): string
    {
        return $grade->letter ?? '—';
    }

    public function apply(Grade $grade, Assessment $assessment, array $input): void
    {
        $grade->letter = $input['letter'] ?? null;
        $grade->raw_score = null;
        $grade->level_code = null;
        $grade->passed = null;
    }

    /** @return array<string, mixed>|null */
    private function band(Assessment $assessment, string $letter): ?array
    {
        foreach ($this->bands($assessment) as $band) {
            if (strcasecmp((string) ($band['label'] ?? ''), $letter) === 0) {
                return $band;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function bands(Assessment $assessment): array
    {
        return $assessment->gradingScheme->config['bands'] ?? [];
    }
}
