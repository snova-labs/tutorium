<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LicenceService;
use App\Support\Licensing\LicenceFile;
use App\Support\Licensing\LicenceProblem;
use Illuminate\Console\Command;

/**
 * What this install's licence says, checked here and now without asking anybody.
 *
 * The first thing an administrator runs when an install refuses an enrollment, and the last thing
 * we ask them to run before they send us a question. It reads the file and verifies its signature
 * locally — the same code path the product itself uses, so an answer here is the answer.
 */
final class LicenceCommand extends Command
{
    protected $signature = 'platform:licence
        {--file= : Check this file instead of the installed one}
        {--json : Print the result as JSON, for a monitor}';

    protected $description = 'Show and verify the licence file, entirely offline';

    public function handle(LicenceService $licences): int
    {
        $path = (string) ($this->option('file') ?: config('licence.path'));

        if ($this->option('file')) {
            return $this->checkFile($path);
        }

        if (! $licences->selfHosted()) {
            $this->info('This install runs in cloud mode, where entitlements come from a subscription. No licence is used.');

            return self::SUCCESS;
        }

        $status = $licences->status();

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ($status['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $licence = $licences->licence();

        if ($licence === null) {
            $this->error($licences->problem() ?? 'No licence.');

            return self::FAILURE;
        }

        $this->table(['', ''], [
            ['File', $path],
            ['Licence', $licence->id],
            ['Licensed to', $licence->licensee],
            ['Issued', $licence->issuedOn->toDateString()],
            ['Expires', sprintf('%s (%d days)', $licence->expiresOn->toDateString(), $licence->daysLeft())],
            ['Grace until', $licence->graceEndsOn()->toDateString()],
            ['Learner cap', $licence->maxLearners === null ? 'unlimited' : (string) $licence->maxLearners],
            ['Learners now', (string) ($status['learners_now'] ?? 0)],
            ['Features', $licence->features === [] ? 'none beyond the cap' : implode(', ', array_keys($licence->features))],
        ]);

        if (($status['warning'] ?? null) !== null) {
            $this->newLine();
            $this->warn((string) $status['warning']);
        }

        return ($status['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /** Verify a file without installing it — worth doing before a renewal goes anywhere near production. */
    private function checkFile(string $path): int
    {
        try {
            $licence = LicenceFile::read(
                $path,
                (string) config('licence.public_key'),
                (int) config('licence.grace_days', 30),
            );
        } catch (LicenceProblem $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s is genuine: licence %s for %s, %s until %s.',
            $path,
            $licence->id,
            $licence->licensee,
            $licence->maxLearners === null ? 'unlimited learners' : $licence->maxLearners.' learners',
            $licence->expiresOn->toDateString(),
        ));

        return self::SUCCESS;
    }
}
