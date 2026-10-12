<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Diagnostics\CheckResult;
use App\Support\Diagnostics\PlatformVerifier;
use Illuminate\Console\Command;

/**
 * Is this release fit to serve?
 *
 * Run after a deploy, and before trusting one. Exits non-zero when any check fails, so a deploy
 * script or a monitor can run it without reading the output.
 */
final class VerifyCommand extends Command
{
    protected $signature = 'platform:verify {--json : Print the results as JSON, for a monitor}';

    protected $description = 'Check that the database, queue, mail, storage and PDF engine all work';

    public function handle(PlatformVerifier $verifier): int
    {
        $results = $verifier->run();
        $failed = array_filter($results, fn (CheckResult $result): bool => $result->failed());

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failed === [],
                'checks' => array_map(fn (CheckResult $result): array => $result->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['', 'Check', 'What was found'],
            array_map(fn (CheckResult $result): array => [
                $this->mark($result->status),
                $result->name,
                $result->detail,
            ], $results),
        );

        if ($failed !== []) {
            $this->newLine();
            $this->error(sprintf(
                '%d of %d checks failed. This release is not ready to serve.',
                count($failed),
                count($results),
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('%d checks passed.', count($results)));

        return self::SUCCESS;
    }

    private function mark(string $status): string
    {
        return match ($status) {
            CheckResult::OK => '<fg=green>ok</>',
            CheckResult::WARN => '<fg=yellow>look</>',
            default => '<fg=red>FAIL</>',
        };
    }
}
