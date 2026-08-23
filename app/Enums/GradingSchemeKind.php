<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six ways this product knows how to grade.
 *
 * Each maps to a strategy class that owns its own validation, normalisation and display. Adding a
 * seventh is one class and one registration — no migration, and nothing in the dashboards,
 * reports or trends changes (SL-ARC-002 §6).
 */
enum GradingSchemeKind: string
{
    /** Out of a maximum the assessment sets. Never fixed at 100. */
    case Points = 'points';

    case Percentage = 'percentage';

    case PassFail = 'pass_fail';

    /** Tenant-defined bands: A–F, A*–U, or German 1–6 where 1 is best. */
    case Letter = 'letter';

    /** An ordered ladder — CEFR A1–C2, or a swimming-stage scale. */
    case Level = 'level';

    /** Named criteria with individual maxima, summed. */
    case Rubric = 'rubric';

    public function label(): string
    {
        return match ($this) {
            self::Points => 'Points',
            self::Percentage => 'Percentage',
            self::PassFail => 'Pass / fail',
            self::Letter => 'Letter grade',
            self::Level => 'Level',
            self::Rubric => 'Rubric',
        };
    }

    /** Kinds whose maximum comes from the assessment rather than the scheme. */
    public function hasPerAssessmentMaximum(): bool
    {
        return in_array($this, [self::Points, self::Rubric], true);
    }
}
