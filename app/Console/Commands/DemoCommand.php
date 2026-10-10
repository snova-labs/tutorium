<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DemoSuite;
use Illuminate\Console\Command;

/**
 * Build or remove the demonstration academies: one of each kind of customer, each with a term
 * already under way. The same thing the staff client's /demo page does, for a terminal.
 *
 * For staging and local development only. It refuses to run on production, because demo academies
 * among customers' would be counted, billed and emailed like any other.
 */
final class DemoCommand extends Command
{
    protected $signature = 'platform:demo
        {--fresh : Remove the existing demo academies first, then build them again}
        {--remove : Remove the demo academies and build nothing}
        {--password= : Password for every demo account (default: DEMO_PASSWORD, else demo-password)}';

    /** @var list<string> */
    protected $aliases = ['platform:demo-academy'];

    protected $description = 'Build (or --remove) the demo academies (not on production)';

    public function handle(DemoSuite $demo): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Demo academies are for staging and development. They are never created on production.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $this->info(sprintf('Removed %d demo academies.', $demo->remove()));

            return self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?: ($demo->password() ?: 'demo-password'));
        $started = microtime(true);
        $built = $demo->build($password, (bool) $this->option('fresh'), fn (string $message) => $this->line($message));

        if ($built === []) {
            $this->warn('The demo academies already exist. Run again with --fresh to rebuild them.');
        } else {
            $this->newLine();
            $this->info(sprintf('Built %d academies in %.0f seconds.', count($built), microtime(true) - $started));
            $rows = [];

            foreach ($built as $academy => $counts) {
                $rows[] = [$academy, $counts['learners'], $counts['marks'], $counts['grades'], $counts['notes'], $counts['reports']];
            }

            $this->table(['Academy', 'Learners', 'Marks', 'Grades', 'Notes', 'Reports'], $rows);
        }

        $this->newLine();
        $this->line('Sign in with any of these, password <comment>'.$password.'</comment>:');

        foreach ($demo->accounts() as $academy) {
            $this->line("<info>{$academy['academy']}</info> ({$academy['kind']})");
            $this->table(['Who', 'Email', 'Role'], array_map(fn ($a) => [$a['name'], $a['email'], $a['role']], $academy['accounts']));
        }

        $this->line('Owners, managers and accountants confirm sign-in with an emailed code: in the API log while MAIL_MAILER=log.');

        return self::SUCCESS;
    }
}
