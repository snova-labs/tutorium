<?php

declare(strict_types=1);

namespace App\Support\Grading;

use App\Enums\GradingSchemeKind;

/**
 * Maps a scheme kind to the class that understands it.
 *
 * This is the extension seam from SL-ARC-002 §6: a new grading scheme is one strategy class and
 * one line here. No migration, and nothing in the reporting layer needs to know it exists.
 */
final class GradingRegistry
{
    /** @var array<string, GradingStrategy> */
    private array $strategies;

    public function __construct()
    {
        $this->strategies = [
            GradingSchemeKind::Points->value => new PointsStrategy,
            GradingSchemeKind::Percentage->value => new PercentageStrategy,
            GradingSchemeKind::PassFail->value => new PassFailStrategy,
            GradingSchemeKind::Letter->value => new LetterStrategy,
            GradingSchemeKind::Level->value => new LevelStrategy,
            GradingSchemeKind::Rubric->value => new RubricStrategy,
        ];
    }

    public function for(GradingSchemeKind $kind): GradingStrategy
    {
        return $this->strategies[$kind->value]
            ?? throw new \RuntimeException("No grading strategy registered for [{$kind->value}].");
    }
}
