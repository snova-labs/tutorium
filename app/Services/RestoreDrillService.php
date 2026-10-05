<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupRun;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\DatabaseImporter;
use App\Support\Backup\Gzip;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Proves the latest backup can be restored (SL-415). A backup nobody has restored is a hope.
 *
 * 1. Both archives must match the SHA-256 recorded in the manifest (nothing truncated or altered).
 * 2. The database dump is restored into the drill database, which is emptied first.
 * 3. The restored copy is dumped again: every table must be present with the same row count and
 *    the same content hash as at backup time.
 * 4. The files archive is unpacked to a temporary folder: same number of files, same total size.
 * 5. The drill database and the temporary folder are emptied again, pass or fail.
 *
 * Any difference fails the drill, records why, and alerts a person.
 */
final class RestoreDrillService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly DatabaseDumper $dumper,
        private readonly DatabaseImporter $importer,
        private readonly BackupAlerts $alerts,
    ) {}

    public function run(?string $name = null): BackupRun
    {
        $run = BackupRun::query()->create([
            'type' => BackupRun::DRILL,
            'status' => BackupRun::RUNNING,
            'started_at' => now(),
        ]);

        $problems = [];
        $details = [];

        try {
            $folder = $this->folder($name);
            $run->update(['name' => basename($folder)]);

            $manifest = json_decode((string) File::get($folder.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

            $problems = [
                ...$this->checkArchives($folder, $manifest),
                ...$this->checkDatabase($folder, $manifest, $details),
                ...$this->checkStorage($folder, $manifest),
            ];
        } catch (Throwable $e) {
            $problems[] = $e->getMessage();
        }

        $run->update([
            'status' => $problems === [] ? BackupRun::OK : BackupRun::FAILED,
            'error' => $problems === [] ? null : 'The restore did not match the backup.',
            'details' => [...$details, 'problems' => $problems],
            'finished_at' => now(),
        ]);

        if ($problems !== []) {
            Log::error('Restore drill failed', ['backup' => $run->name, 'problems' => $problems]);
            $this->alerts->failed($run);
        }

        return $run->refresh();
    }

    /** The named backup folder, or the newest complete one. */
    private function folder(?string $name): string
    {
        $root = $this->backups->root();

        if ($name !== null) {
            $folder = $root.'/'.basename($name);

            if (! is_file($folder.'/manifest.json')) {
                throw new RuntimeException("There is no backup called {$name}.");
            }

            return $folder;
        }

        $folders = is_dir($root) ? File::directories($root) : [];
        $complete = array_values(array_filter($folders, fn (string $dir): bool => is_file($dir.'/manifest.json')));
        sort($complete);

        return end($complete) ?: throw new RuntimeException('There is no backup to restore. Run platform:backup first.');
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function checkArchives(string $folder, array $manifest): array
    {
        $problems = [];
        $archives = [$manifest['database']];

        if ($manifest['storage'] !== null) {
            $archives[] = $manifest['storage'];
        }

        foreach ($archives as $archive) {
            $path = $folder.'/'.$archive['file'];

            if (! is_file($path)) {
                $problems[] = "{$archive['file']} is missing.";
            } elseif (hash_file('sha256', $path) !== $archive['sha256']) {
                $problems[] = "{$archive['file']} does not match its checksum: it was altered or truncated.";
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $details
     * @return list<string>
     */
    private function checkDatabase(string $folder, array $manifest, array &$details): array
    {
        $drill = $this->drillConnection();

        try {
            $this->empty($drill);
            $details['statements'] = $this->importer->import($drill, $folder.'/'.$manifest['database']['file']);

            $restored = $this->dumper->dump($drill, fn (string $line) => null, (array) config('backup.structure_only', []));
            $expected = $manifest['database']['tables'];
            $problems = [];

            foreach ($expected as $table => $stats) {
                if (! isset($restored[$table])) {
                    $problems[] = "Table {$table} is missing after the restore.";
                } elseif ($restored[$table]['rows'] !== $stats['rows']) {
                    $problems[] = "Table {$table}: {$restored[$table]['rows']} rows restored, {$stats['rows']} backed up.";
                } elseif ($restored[$table]['sha256'] !== $stats['sha256']) {
                    $problems[] = "Table {$table}: the restored rows differ from the backup.";
                }
            }

            foreach (array_diff_key($restored, $expected) as $table => $stats) {
                $problems[] = "Table {$table} appeared in the restore but was not backed up.";
            }

            $details['tables'] = count($restored);
            $details['rows'] = array_sum(array_column($restored, 'rows'));

            return $problems;
        } finally {
            $this->empty($drill);
            DB::purge('backup_drill');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function checkStorage(string $folder, array $manifest): array
    {
        if ($manifest['storage'] === null) {
            return [];
        }

        $target = storage_path('framework/restore-drill-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($target);

        try {
            $tar = $target.'.tar';
            Gzip::decompress($folder.'/'.$manifest['storage']['file'], $tar);
            Process::run(['tar', '-xf', $tar, '-C', $target])->throw();
            @unlink($tar);

            $files = File::allFiles($target, true);
            $bytes = array_sum(array_map(fn ($f): int => (int) $f->getSize(), $files));
            $problems = [];

            if (count($files) !== $manifest['storage']['files']) {
                $problems[] = sprintf('Files: %d restored, %d backed up.', count($files), $manifest['storage']['files']);
            }

            if ($bytes !== $manifest['storage']['bytes']) {
                $problems[] = sprintf('Files: %d bytes restored, %d backed up.', $bytes, $manifest['storage']['bytes']);
            }

            return $problems;
        } finally {
            File::deleteDirectory($target);
        }
    }

    private function drillConnection(): Connection
    {
        $drill = (string) config('backup.drill_database');
        $live = (string) DB::connection()->getDatabaseName();

        if ($drill === '') {
            throw new RuntimeException('BACKUP_DRILL_DATABASE is not set, so there is nowhere to restore into.');
        }

        // The drill drops every table in its database. Pointed at the live one, it would be the
        // outage it exists to prevent.
        if ($drill === $live) {
            throw new RuntimeException('BACKUP_DRILL_DATABASE is the live database. Refusing to restore over it.');
        }

        config(['database.connections.backup_drill.database' => $drill]);
        DB::purge('backup_drill');

        return DB::connection('backup_drill');
    }

    private function empty(Connection $connection): void
    {
        $connection->unprepared('SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->dumper->tables($connection) as $table) {
            $connection->unprepared('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
        }

        $connection->unprepared('SET FOREIGN_KEY_CHECKS=1');
    }
}
