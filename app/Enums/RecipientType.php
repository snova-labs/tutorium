<?php

declare(strict_types=1);

namespace App\Enums;

enum RecipientType: string
{
    case Guardian = 'guardian';
    /** Adult learners in language schools and skills institutes receive their own reports. */
    case Learner = 'learner';
    /** Employer-funded training, where the company receives the report. */
    case Sponsor = 'sponsor';
}
