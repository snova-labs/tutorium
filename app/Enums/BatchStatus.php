<?php

declare(strict_types=1);

namespace App\Enums;

enum BatchStatus: string
{
    case Planned = 'planned';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function acceptsEnrollments(): bool
    {
        return in_array($this, [self::Planned, self::Running], true);
    }
}
