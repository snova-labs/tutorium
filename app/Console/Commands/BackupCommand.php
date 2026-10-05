<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\BackupService;
use Illuminate\Console\Command;

final class BackupCommand extends Command
{
    protected $signature = 'platform:backup';

    protected $description = 'Back up the database and uploaded files, with a manifest the restore drill checks against';

    public function handle(BackupService $backups): int
    {
        $run = $backups->run();

        if ($run->status !== BackupRun::OK) {
            $this->error("Backup failed: {$run->error}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup %s: %d tables, %d rows, %d files, %s.',
            $run->name,
            $run->details['tables'] ?? 0,
            $run->details['rows'] ?? 0,
            $run->details['files'] ?? 0,
            number_format(($run->bytes ?? 0) / 1048576, 1).' MB',
        ));

        return self::SUCCESS;
    }
}
