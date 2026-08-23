<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';

    /**
     * Some reports generated, some did not.
     *
     * A distinct state rather than a failure, because one learner with a broken record must never
     * stop the other thirty-nine reports going out (FR-RPT-3).
     */
    case CompletedWithFailures = 'completed_with_failures';

    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithFailures, self::Failed], true);
    }
}
