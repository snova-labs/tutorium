<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryMode: string
{
    case InPerson = 'in_person';
    case Online = 'online';
    case Hybrid = 'hybrid';

    /** Online and hybrid batches are the ones a meeting link belongs on. */
    public function needsMeetingLink(): bool
    {
        return $this !== self::InPerson;
    }
}
