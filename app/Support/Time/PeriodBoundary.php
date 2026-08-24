<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Enums\PeriodType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A reporting window, expressed in both the terms a person agreed to and the terms the database
 * stores.
 *
 * @implements Arrayable<string, mixed>
 */
final class PeriodBoundary implements Arrayable
{
    public function __construct(
        public readonly PeriodType $type,
        public readonly string $label,
        public readonly CarbonImmutable $startsLocalDate,
        public readonly CarbonImmutable $endsLocalDate,
        public readonly CarbonImmutable $startsAtUtc,
        public readonly CarbonImmutable $endsAtUtc,
        public readonly string $timezone,
    ) {}

    public function contains(CarbonImmutable $instant): bool
    {
        return $instant >= $this->startsAtUtc && $instant <= $this->endsAtUtc;
    }

    public function days(): int
    {
        return (int) $this->startsLocalDate->diffInDays($this->endsLocalDate) + 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label,
            'starts_local_date' => $this->startsLocalDate->toDateString(),
            'ends_local_date' => $this->endsLocalDate->toDateString(),
            'starts_at_utc' => $this->startsAtUtc->toIso8601String(),
            'ends_at_utc' => $this->endsAtUtc->toIso8601String(),
            'timezone' => $this->timezone,
        ];
    }
}
