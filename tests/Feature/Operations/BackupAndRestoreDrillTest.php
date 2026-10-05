<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\BackupRun;
use App\Models\Operator;
use App\Models\Tenant;
use App\Notifications\BackupFailedNotification;
use App\Services\BackupService;
use App\Services\RestoreDrillService;
use App\Services\TenantProvisioner;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\DatabaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SL-415: a backup nobody has restored is a hope. These prove the drill restores a backup and,
 * more importantly, that it notices when a backup is not what was saved.
 */
final class BackupAndRestoreDrillTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $files;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->root = sys_get_temp_dir().'/tutorium-backups-'.bin2hex(random_bytes(4));
        $this->files = sys_get_temp_dir().'/tutorium-files-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->files.'/private/reports');
        File::put($this->files.'/private/reports/term-1.pdf', str_repeat('%PDF', 100));
        File::put($this->files.'/private/logo.png', 'png-bytes');

        $drill = DB::connection()->getDatabaseName().'_drill';

        // Created outside Laravel's connection: CREATE DATABASE would commit the test's transaction.
        $config = config('database.connections.mysql');
        (new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']))
            ->exec("CREATE DATABASE IF NOT EXISTS `{$drill}`");

        config([
            'backup.path' => $this->root,
            'backup.files_path' => $this->files,
            'backup.drill_database' => $drill,
            'backup.alert_to' => 'ops@example.test',
        ]);

        app(TenantProvisioner::class)->provision([
            'name' => "O'Brien & Sons\nTutoring \\ Centre ✓",
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->files);

        parent::tearDown();
    }

    #[Test]
    public function a_backup_restores_completely_in_the_drill(): void
    {
        $backup = app(BackupService::class)->run();

        $this->assertSame(BackupRun::OK, $backup->status, (string) $backup->error);
        $this->assertSame(2, $backup->details['files']);
        $this->assertFileExists($this->root.'/'.$backup->name.'/database.sql.gz');
        $this->assertFileExists($this->root.'/'.$backup->name.'/storage.tar.gz');

        $drill = app(RestoreDrillService::class)->run();

        $this->assertSame(BackupRun::OK, $drill->status, implode("\n", $drill->details['problems'] ?? []));
        $this->assertSame($backup->name, $drill->name);
        $this->assertGreaterThan(50, $drill->details['tables']);
        Notification::assertNothingSent();

        // The drill database is left empty.
        $this->assertSame([], app(DatabaseDumper::class)->tables(DB::connection('backup_drill')));
    }

    #[Test]
    public function awkward_text_survives_the_round_trip_exactly(): void
    {
        $path = $this->root.'/round-trip.sql.gz';
        File::ensureDirectoryExists($this->root);
        $gz = gzopen($path, 'wb');
        app(DatabaseDumper::class)->dump(DB::connection(), function (string $line) use ($gz): void {
            gzwrite($gz, $line."\n");
        });
        gzclose($gz);

        config(['database.connections.backup_drill.database' => config('backup.drill_database')]);
        DB::purge('backup_drill');
        $drill = DB::connection('backup_drill');

        try {
            app(DatabaseImporter::class)->import($drill, $path);

            $this->assertSame(
                "O'Brien & Sons\nTutoring \\ Centre ✓",
                $drill->table('tenants')->value('name'),
            );
        } finally {
            $drill->unprepared('SET FOREIGN_KEY_CHECKS=0');
            foreach (app(DatabaseDumper::class)->tables($drill) as $table) {
                $drill->unprepared("DROP TABLE `{$table}`");
            }
        }
    }

    #[Test]
    public function an_altered_backup_fails_the_drill_and_alerts_someone(): void
    {
        $backup = app(BackupService::class)->run();
        $dump = $this->root.'/'.$backup->name.'/database.sql.gz';

        // Lose every row of one table, as a truncated or tampered file would.
        $lines = array_filter(
            explode("\n", (string) gzdecode((string) file_get_contents($dump))),
            fn (string $line): bool => ! str_starts_with($line, 'INSERT INTO `tenants`'),
        );
        file_put_contents($dump, gzencode(implode("\n", $lines)));

        $drill = app(RestoreDrillService::class)->run();

        $this->assertSame(BackupRun::FAILED, $drill->status);
        $this->assertContains('database.sql.gz does not match its checksum: it was altered or truncated.', $drill->details['problems']);
        $this->assertContains('Table tenants: 0 rows restored, 1 backed up.', $drill->details['problems']);

        Notification::assertSentOnDemand(BackupFailedNotification::class);
    }

    #[Test]
    public function missing_files_fail_the_drill(): void
    {
        $backup = app(BackupService::class)->run();
        $manifestPath = $this->root.'/'.$backup->name.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $manifest['storage']['files'] = 3;
        file_put_contents($manifestPath, json_encode($manifest));

        $drill = app(RestoreDrillService::class)->run();

        $this->assertSame(BackupRun::FAILED, $drill->status);
        $this->assertContains('Files: 2 restored, 3 backed up.', $drill->details['problems']);
    }

    #[Test]
    public function the_drill_refuses_to_restore_over_the_live_database(): void
    {
        app(BackupService::class)->run();
        config(['backup.drill_database' => DB::connection()->getDatabaseName()]);

        $drill = app(RestoreDrillService::class)->run();

        $this->assertSame(BackupRun::FAILED, $drill->status);
        $this->assertStringContainsString('is the live database', $drill->details['problems'][0]);
        $this->assertSame(1, Tenant::query()->count(), 'The live data is untouched.');
    }

    #[Test]
    public function a_drill_with_no_backup_to_restore_fails_loudly(): void
    {
        $drill = app(RestoreDrillService::class)->run();

        $this->assertSame(BackupRun::FAILED, $drill->status);
        $this->assertStringContainsString('no backup to restore', $drill->details['problems'][0]);
        Notification::assertSentOnDemand(BackupFailedNotification::class);
    }

    #[Test]
    public function caches_sessions_and_queued_jobs_are_backed_up_as_structure_only(): void
    {
        DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => time()]);

        $backup = app(BackupService::class)->run();
        $sql = (string) gzdecode((string) file_get_contents($this->root.'/'.$backup->name.'/database.sql.gz'));

        $this->assertStringContainsString('CREATE TABLE `sessions`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `sessions`', $sql);
    }

    #[Test]
    public function old_backups_are_pruned(): void
    {
        config(['backup.keep' => 2]);

        foreach (['2026-01-01_010000', '2026-01-02_010000', '2026-01-03_010000'] as $old) {
            File::ensureDirectoryExists($this->root.'/'.$old);
            File::put($this->root.'/'.$old.'/manifest.json', '{}');
        }

        $backup = app(BackupService::class)->run();

        $this->assertSame(
            ['2026-01-03_010000', $backup->name],
            array_map(basename(...), File::directories($this->root)),
        );
    }

    #[Test]
    public function a_backup_that_cannot_be_written_fails_and_alerts_operators(): void
    {
        config(['backup.alert_to' => null, 'backup.path' => '/proc/no-backups-here']);
        $operator = Operator::factory()->create(['is_active' => true]);

        $backup = app(BackupService::class)->run();

        $this->assertSame(BackupRun::FAILED, $backup->status);
        Notification::assertSentTo($operator, BackupFailedNotification::class);
    }

    #[Test]
    public function the_status_command_fails_until_both_have_succeeded_recently(): void
    {
        $this->artisan('platform:backups')->assertFailed();

        app(BackupService::class)->run();
        $this->artisan('platform:backups')->assertFailed();

        app(RestoreDrillService::class)->run();
        $this->artisan('platform:backups')->assertSuccessful();

        $this->travel(27)->hours();
        $this->artisan('platform:backups')->assertFailed();
    }
}
