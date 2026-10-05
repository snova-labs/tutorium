<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;

/**
 * When the data was last saved, and when a restore of it was last proven.
 *
 * Exits non-zero when either is missing, failed or older than allowed, so a monitor can run it.
 */
final class BackupStatusCommand extends Command
{
    protected $signature = 'platform:backups';

    protected $description = 'Show the latest backup and restore drill, failing when either is stale';

    public function handle(): int
    {
        $healthy = true;
        $rows = [];

        foreach ([BackupRun::BACKUP => 'Backup', BackupRun::DRILL => 'Restore drill'] as $type => $label) {
            $latest = BackupRun::query()->where('type', $type)->latest('started_at')->latest('id')->first();
            $ok = BackupRun::latestOk($type);
            $maxHours = (int) config("backup.max_age_hours.{$type}");

            $fresh = $ok !== null && $ok->started_at->gt(now()->subHours($maxHours));
            $healthy = $healthy && $fresh && $latest?->status !== BackupRun::FAILED;

            $rows[] = [
                $label,
                $latest === null ? 'never' : $latest->status,
                $latest->name ?? '',
                $ok?->started_at->diffForHumans() ?? 'never',
                $fresh ? 'yes' : "no (limit {$maxHours}h)",
            ];
        }

        $this->table(['', 'Last run', 'Backup', 'Last success', 'Recent enough'], $rows);

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
