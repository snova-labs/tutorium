<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * What has to be true for this release to serve its first request.
 *
 * Each check talks to the thing it is checking rather than reading configuration about it: a
 * correct MAIL_HOST in the environment and a mail server nobody can reach look identical on
 * paper, and the second one is a silent outage of every report the academy sends.
 */
final class PlatformVerifier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Migrator $migrator,
        private readonly FilesystemManager $filesystems,
        private readonly QueueFactory $queue,
        private readonly MailManager $mail,
        private readonly PdfRenderer $pdf,
    ) {}

    /** @return array<int, CheckResult> */
    public function run(): array
    {
        $checks = [
            'Settings' => fn (): CheckResult => $this->settings(),
            'Database' => fn (): CheckResult => $this->database(),
            'Migrations' => fn (): CheckResult => $this->migrations(),
            'Cache' => fn (): CheckResult => $this->cache(),
            'Queue' => fn (): CheckResult => $this->queueConnection(),
            'Failed jobs' => fn (): CheckResult => $this->failedJobs(),
            'Mail' => fn (): CheckResult => $this->mailTransport(),
            'Report PDFs' => fn (): CheckResult => $this->pdfRenderer(),
        ];

        foreach ($this->disks() as $label => $disk) {
            $checks[$label] = fn (): CheckResult => $this->storage($label, $disk);
        }

        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $results[] = $check();
            } catch (Throwable $e) {
                // One check falling over must not hide the others: a deploy wants the whole list.
                $results[] = CheckResult::fail($name, Str::limit($e->getMessage(), 160));
            }
        }

        return $results;
    }

    private function settings(): CheckResult
    {
        $problems = [];

        if (config('app.key') === null || config('app.key') === '') {
            $problems[] = 'APP_KEY is not set';
        }

        if (app()->isProduction()) {
            if (config('app.debug')) {
                // Debug on in production prints stack traces, environment and queries to whoever
                // triggers an error.
                $problems[] = 'APP_DEBUG is on';
            }

            if (Str::contains((string) config('app.url'), ['localhost', '127.0.0.1'])) {
                $problems[] = 'APP_URL still points at localhost, so emailed links will not work';
            }
        }

        return $problems === []
            ? CheckResult::ok('Settings', sprintf('%s, debug %s', app()->environment(), config('app.debug') ? 'on' : 'off'))
            : CheckResult::fail('Settings', implode('; ', $problems));
    }

    private function database(): CheckResult
    {
        $connection = $this->db->connection();
        $connection->select('select 1');

        return CheckResult::ok('Database', sprintf(
            '%s, %s',
            $connection->getDriverName(),
            $connection->getDatabaseName(),
        ));
    }

    private function migrations(): CheckResult
    {
        if (! $this->migrator->repositoryExists()) {
            return CheckResult::fail('Migrations', 'None have run: the schema is not there yet.');
        }

        $ran = $this->migrator->getRepository()->getRan();
        $pending = [];

        foreach ($this->migrator->getMigrationFiles($this->migrator->paths() + [database_path('migrations')]) as $name => $path) {
            if (! in_array($name, $ran, true)) {
                $pending[] = $name;
            }
        }

        if ($pending === []) {
            return CheckResult::ok('Migrations', sprintf('%d run, none pending', count($ran)));
        }

        // Code that expects a column the database does not have fails at the worst moment: when
        // somebody uses the feature it was written for.
        return CheckResult::fail('Migrations', sprintf(
            '%d pending, starting with %s',
            count($pending),
            $pending[0],
        ));
    }

    private function cache(): CheckResult
    {
        $key = 'platform:verify:'.Str::random(8);
        Cache::put($key, 'probe', 30);
        $read = Cache::get($key);
        Cache::forget($key);

        return $read === 'probe'
            ? CheckResult::ok('Cache', (string) config('cache.default'))
            : CheckResult::fail('Cache', sprintf('%s accepted a write but did not return it', config('cache.default')));
    }

    private function queueConnection(): CheckResult
    {
        $name = (string) config('queue.default');
        $size = $this->queue->connection()->size();

        if ($name === 'sync') {
            // Sync runs jobs inside the request. Reports then block whoever asked for them, and a
            // failure takes the request down with it.
            return CheckResult::warn('Queue', 'sync: jobs run inside the request, with no worker');
        }

        return CheckResult::ok('Queue', sprintf('%s, %d waiting', $name, $size));
    }

    private function failedJobs(): CheckResult
    {
        // Countable in every driver Laravel ships; the interface itself does not promise it.
        $count = (int) app('queue.failer')->count();

        return $count === 0
            ? CheckResult::ok('Failed jobs', 'none')
            : CheckResult::warn('Failed jobs', sprintf('%d waiting; read them with queue:failed', $count));
    }

    private function mailTransport(): CheckResult
    {
        $mailer = (string) config('mail.default');
        $transport = $this->mail->mailer($mailer)->getSymfonyTransport();

        if ($mailer !== 'smtp') {
            return CheckResult::ok('Mail', sprintf('%s (%s)', $mailer, (string) $transport));
        }

        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');
        $socket = @fsockopen($host, $port, $code, $message, 3.0);

        if ($socket === false) {
            // A correct host in the environment and a mail server nobody can reach look the same
            // on paper. Only the connection tells them apart.
            return CheckResult::fail('Mail', sprintf('cannot reach %s:%d (%s)', $host, $port, $message ?: 'no route'));
        }

        fclose($socket);

        return CheckResult::ok('Mail', sprintf('smtp, %s:%d answers', $host, $port));
    }

    private function pdfRenderer(): CheckResult
    {
        $bytes = $this->pdf->render(
            '<!doctype html><html lang="en"><body><p>platform:verify</p></body></html>',
            new PdfOptions(title: 'platform:verify'),
        );

        return str_starts_with($bytes, '%PDF')
            ? CheckResult::ok('Report PDFs', sprintf('%s, %d bytes for a one-line page', $this->pdf->name(), strlen($bytes)))
            : CheckResult::fail('Report PDFs', sprintf('%s returned something that is not a PDF', $this->pdf->name()));
    }

    private function storage(string $label, string $disk): CheckResult
    {
        $filesystem = $this->filesystems->disk($disk);
        $path = 'platform-verify/'.Str::random(12).'.txt';

        $filesystem->put($path, 'probe');
        $read = $filesystem->get($path);
        $filesystem->delete($path);

        return $read === 'probe'
            ? CheckResult::ok($label, sprintf('%s, readable and writable', $disk))
            : CheckResult::fail($label, sprintf('%s accepted a write but did not return it', $disk));
    }

    /**
     * The disks the application actually writes to, each checked once however they are configured.
     *
     * @return array<string, string>
     */
    private function disks(): array
    {
        $disks = [
            'Report storage' => (string) config('reporting.storage.disk'),
            'Logo storage' => (string) config('branding.logo.disk'),
        ];

        $seen = [];

        foreach ($disks as $label => $disk) {
            if (in_array($disk, $seen, true)) {
                unset($disks[$label]);

                continue;
            }

            $seen[] = $disk;
        }

        return $disks;
    }
}
