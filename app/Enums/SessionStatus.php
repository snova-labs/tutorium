<?php

declare(strict_types=1);

namespace App\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Held = 'held';
    case Cancelled = 'cancelled';

    /**
     * Whether this session belongs in an attendance percentage denominator.
     *
     * A cancelled class was not an opportunity the learner missed, so it leaves the denominator
     * entirely rather than counting against anyone.
     */
    public function countsTowardAttendance(): bool
    {
        return $this === self::Held;
    }
}
