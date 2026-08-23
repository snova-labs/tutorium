<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Models\Assessment;
use App\Models\Grade;

/**
 * How one grading scheme reads, checks and displays a result.
 *
 * The contract exists so that everything downstream — averages, trends, reports, at-risk lists —
 * can work with a single normalised percentage and never learn what a rubric or a CEFR level is.
 */
interface GradingStrategy
{
    /**
     * Convert a stored result into a comparable 0–100 value.
     *
     * Returns null where the result should leave the denominator rather than count as zero.
     */
    public function normalize(Grade $grade, Assessment $assessment): ?float;

    /**
     * Reject a value that cannot mean anything under this scheme, with a message a teacher can act
     * on rather than a type error.
     *
     * @param array<string, mixed> $input
     */
    public function validate(array $input, Assessment $assessment): void;

    /** How the result reads on a report: "17 / 20", "B1", "Pass". */
    public function display(Grade $grade, Assessment $assessment): string;

    /** @param array<string, mixed> $input */
    public function apply(Grade $grade, Assessment $assessment, array $input): void;
}
