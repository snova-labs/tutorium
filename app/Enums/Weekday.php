<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ISO-8601 weekday numbering: Monday is 1, Sunday is 7.
 *
 * Deliberately independent of the branch's week_start setting. Which day a week *starts* on is a
 * display preference that differs between Kathmandu, Berlin and Dubai; which day a class falls on
 * is a fact. Storing the fact in a fixed numbering means changing the display preference can never
 * silently move a timetable.
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("Unknown weekday [{$name}].");
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_map(static fn (self $c) => strtolower($c->name), self::cases());
    }
}
