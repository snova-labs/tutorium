<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TrialService;
use Illuminate\Console\Command;

final class RunTrialsCommand extends Command
{
    protected $signature = 'platform:trials';

    protected $description = 'Send trial reminders and move expired trials to read-only';

    public function handle(TrialService $trials): int
    {
        $reminders = $trials->sendDueReminders();
        $expired = $trials->expireDue();

        $this->table(
            ['Reminders sent', 'Trials expired'],
            [[$reminders['sent'], $expired['expired']]],
        );

        return self::SUCCESS;
    }
}
