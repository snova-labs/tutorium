<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupRun;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\Gzip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * The nightly backup (SL-415): the database and every uploaded or generated file, with a manifest.
 *
 * Each run writes one folder: `database.sql.gz`, `storage.tar.gz` (when there are files) and
 * `manifest.json`, which records each table's row count and content hash, the files' count and
 * size, and the SHA-256 of both archives. The restore drill checks a restore against exactly that.
 */
final class BackupService
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupAlerts $alerts,
    ) {}

    public function run(): BackupRun
    {
        $name = now()->utc()->format('Y-m-d_His');
        $folder = $this->root().'/'.$name;

        $run = BackupRun::query()->create([
            'type' => BackupRun::BACKUP,
            'status' => BackupRun::RUNNING,
            'name' => $name,
            'started_at' => now(),
        ]);

        try {
            File::ensureDirectoryExists($folder, 0700);

            $tables = $this->dumpDatabase($folder.'/database.sql.gz');
            $storage = $this->archiveStorage($folder.'/storage.tar.gz');

            $manifest = [
                'format' => 1,
                'name' => $name,
                'created_at' => now()->utc()->toIso8601String(),
                'database' => [
                    'file' => 'database.sql.gz',
                    'sha256' => hash_file('sha256', $folder.'/database.sql.gz'),
                    'tables' => $tables,
                ],
                'storage' => $storage,
            ];

            File::put($folder.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $bytes = array_sum(array_map(fn ($file): int => (int) $file->getSize(), File::files($folder)));

            $run->update([
                'status' => BackupRun::OK,
                'bytes' => $bytes,
                'details' => [
                    'tables' => count($tables),
                    'rows' => array_sum(array_column($tables, 'rows')),
                    'files' => $storage['files'] ?? 0,
                ],
                'finished_at' => now(),
            ]);

            $this->prune();
        } catch (Throwable $e) {
            File::deleteDirectory($folder);

            $run->update(['status' => BackupRun::FAILED, 'error' => $e->getMessage(), 'finished_at' => now()]);
            Log::error('Backup failed', ['backup' => $name, 'error' => $e->getMessage()]);
            $this->alerts->failed($run);
        }

        return $run->refresh();
    }

    public function root(): string
    {
        return rtrim((string) config('backup.path'), '/');
    }

    /** @return array<string, array{rows: int, sha256: string}> */
    private function dumpDatabase(string $path): array
    {
        $gz = gzopen($path, 'wb6');

        if ($gz === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        try {
            return $this->dumper->dump(
                DB::connection(),
                function (string $line) use ($gz): void {
                    gzwrite($gz, $line."\n");
                },
                (array) config('backup.structure_only', []),
            );
        } finally {
            gzclose($gz);
        }
    }

    /** @return array{file: string, sha256: string, files: int, bytes: int}|null */
    private function archiveStorage(string $path): ?array
    {
        $source = (string) config('backup.files_path');
        $files = is_dir($source) ? File::allFiles($source, true) : [];

        if ($files === []) {
            return null;
        }

        // The system tar rather than PharData: Phar caches an archive per path inside the process,
        // so a drill in the same process could read a stale copy of one just written. Compressed in
        // PHP, because the image has no gzip binary for tar to call.
        $tar = substr($path, 0, -3);
        Process::run(['tar', '-cf', $tar, '-C', $source, '.'])->throw();
        Gzip::compress($tar, $path);
        @unlink($tar);

        return [
            'file' => basename($path),
            'sha256' => hash_file('sha256', $path),
            'files' => count($files),
            'bytes' => array_sum(array_map(fn ($f): int => (int) $f->getSize(), $files)),
        ];
    }

    /** Keep the newest `backup.keep` folders; a failed run never counts towards them. */
    private function prune(): void
    {
        $keep = max(1, (int) config('backup.keep', 14));
        $folders = collect(File::directories($this->root()))
            ->filter(fn (string $dir): bool => is_file($dir.'/manifest.json'))
            ->sort()
            ->values();

        foreach ($folders->slice(0, max(0, $folders->count() - $keep)) as $old) {
            File::deleteDirectory($old);
        }
    }
}
