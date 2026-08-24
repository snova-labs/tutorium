<?php

declare(strict_types=1);

namespace App\Support\Attendance;

use Illuminate\Contracts\Support\Arrayable;

/**
 * An attendance figure and everything needed to defend it.
 *
 * The unmarked count is reported separately and never quietly folded into the percentage. Two of
 * eight sessions unmarked is neither 100% nor 75% — it is 100% of what is known, with a gap that
 * someone needs to close before a report goes out. Hiding that choice inside the arithmetic is
 * how a parent ends up disputing a number nobody can explain.
 *
 * @implements Arrayable<string, mixed>
 */
final class AttendanceRate implements Arrayable
{
    public function __construct(
        public readonly int $attended,
        public readonly int $counted,
        public readonly int $unmarked,
        public readonly int $excused,
        public readonly int $madeUp,
        public readonly bool $isCompulsory,
    ) {}

    public function percentage(): ?float
    {
        if ($this->counted === 0) {
            return null;
        }

        return round(($this->attended / $this->counted) * 100, 1);
    }

    /** False when sessions are still unmarked — reports check this before sending. */
    public function isComplete(): bool
    {
        return $this->unmarked === 0;
    }

    public function isBelow(int $thresholdPct): bool
    {
        // A batch that does not treat attendance as compulsory produces no risk flags at all.
        if (! $this->isCompulsory) {
            return false;
        }

        $pct = $this->percentage();

        return $pct !== null && $pct < $thresholdPct;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attended' => $this->attended,
            'counted' => $this->counted,
            'percentage' => $this->percentage(),
            'unmarked' => $this->unmarked,
            'excused' => $this->excused,
            'made_up' => $this->madeUp,
            'is_complete' => $this->isComplete(),
            'is_informational' => ! $this->isCompulsory,
        ];
    }
}
