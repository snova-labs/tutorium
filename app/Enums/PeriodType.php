<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a course divides time for reporting.
 *
 * The single most consequential internationalisation decision in the schema: a month string would
 * have worked for a Nepali tutoring centre and failed for every language school on earth
 * (SL-LOC-005 §5).
 */
enum PeriodType: string
{
    /** Calendar month in the batch timezone. Tutoring centres. */
    case Monthly = 'monthly';

    /** Named ranges defined per course. Language schools, school-adjacent programmes. */
    case Term = 'term';

    /** Three-month blocks anchored to a configurable start month. Corporate training. */
    case Quarter = 'quarter';

    /** Fixed-length blocks counted from the batch start. Bootcamps, skills institutes. */
    case Block = 'block';

    /** Explicit start and end dates. Anything irregular. */
    case Custom = 'custom';

    /** True where boundaries are computed; false where a person defines them row by row. */
    public function isComputed(): bool
    {
        return in_array($this, [self::Monthly, self::Quarter, self::Block], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Term => 'Term',
            self::Quarter => 'Quarter',
            self::Block => 'Cohort block',
            self::Custom => 'Custom dates',
        };
    }
}
