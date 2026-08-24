<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DunningService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

final class RunDunningCommand extends Command
{
    protected $signature = 'platform:dunning {--dry-run : Report what would happen without charging anything}';

    protected $description = 'Attempt collection on unpaid invoices and apply due plan changes';

    public function handle(DunningService $dunning, SubscriptionService $subscriptions): int
    {
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing will be charged.');

            return self::SUCCESS;
        }

        $applied = $subscriptions->applyPendingChanges();
        $summary = $dunning->run();

        $this->table(
            ['Attempted', 'Recovered', 'Suspended', 'Plan changes applied'],
            [[$summary['attempted'], $summary['recovered'], $summary['suspended'], $applied]],
        );

        return self::SUCCESS;
    }
}
