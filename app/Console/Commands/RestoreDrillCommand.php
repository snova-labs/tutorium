<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\RestoreDrillService;
use Illuminate\Console\Command;

final class RestoreDrillCommand extends Command
{
    protected $signature = 'platform:restore-drill {backup? : A backup folder name; the newest when left out}';

    protected $description = 'Restore a backup into the drill database and prove it matches what was backed up';

    public function handle(RestoreDrillService $drill): int
    {
        $run = $drill->run($this->argument('backup'));

        if ($run->status !== BackupRun::OK) {
            $this->error("Restore drill of {$run->name} failed:");

            foreach ($run->details['problems'] ?? [] as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restored %s: %d tables and %d rows match the backup.',
            $run->name,
            $run->details['tables'] ?? 0,
            $run->details['rows'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
