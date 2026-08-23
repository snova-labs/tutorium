<?php

declare(strict_types=1);

namespace App\Support\Grading;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A period average with its own arithmetic attached.
 *
 * A number a parent will read should be one a teacher can defend line by line, so the breakdown
 * travels with the figure rather than being reconstructed by whichever screen displays it.
 *
 * @implements Arrayable<string, mixed>
 */
final class PeriodAverage implements Arrayable
{
    /**
     * @param  array<int, array{type: string, mean: float, weight: ?float, contribution: ?float, counted: int, excluded: int}>  $breakdown
     */
    public function __construct(
        public readonly ?float $percentage,
        public readonly bool $isWeighted,
        public readonly float $weightsUsed,
        public readonly array $breakdown,
        public readonly int $graded,
        public readonly int $missing,
        public readonly int $excluded,
        public readonly int $ungraded,
    ) {}

    /** False while assessments are still ungraded — report generation checks this before sending. */
    public function isComplete(): bool
    {
        return $this->ungraded === 0;
    }

    /**
     * A sentence explaining the figure, for the tooltip beside it.
     */
    public function explanation(): string
    {
        if ($this->percentage === null) {
            return 'Nothing has been graded in this period yet.';
        }

        if (! $this->isWeighted) {
            return sprintf('Simple average of %d graded %s.', $this->graded, $this->graded === 1 ? 'result' : 'results');
        }

        if (abs($this->weightsUsed - 100.0) > 0.01) {
            return sprintf(
                'Weighted average. %s of the usual weighting applies this period, because %d %s excluded — '
                .'the remaining weights were rescaled.',
                rtrim(rtrim(number_format($this->weightsUsed, 1), '0'), '.').'%',
                $this->excluded,
                $this->excluded === 1 ? 'result was' : 'results were',
            );
        }

        return 'Weighted average across assessment types.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'percentage' => $this->percentage,
            'is_weighted' => $this->isWeighted,
            'weights_used' => $this->weightsUsed,
            'breakdown' => $this->breakdown,
            'graded' => $this->graded,
            'missing' => $this->missing,
            'excluded' => $this->excluded,
            'ungraded' => $this->ungraded,
            'is_complete' => $this->isComplete(),
            'explanation' => $this->explanation(),
        ];
    }
}
