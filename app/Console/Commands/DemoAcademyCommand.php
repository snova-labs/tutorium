<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\DemoAcademyBuilder;
use App\Services\TenantLifecycleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Build the demonstration academy: a term in progress, ready to show.
 *
 * For staging and local development only. It refuses to run on production, because a demo
 * academy among customers' would be counted, billed and emailed like any other.
 */
final class DemoAcademyCommand extends Command
{
    protected $signature = 'platform:demo-academy
        {--fresh : Remove the existing demo academy first, and build it again}
        {--password= : Password for every demo account (default: demo-password)}';

    protected $description = 'Create a demo academy with a term of realistic data (not on production)';

    public function handle(DemoAcademyBuilder $builder, TenantLifecycleService $lifecycle, TenantContext $tenancy): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('The demo academy is for staging and development. It is never created on production.');

            return self::FAILURE;
        }

        if ($builder->exists()) {
            if (! $this->option('fresh')) {
                $this->warn('The demo academy already exists. Run again with --fresh to rebuild it.');

                return self::SUCCESS;
            }

            $this->line('Removing the existing demo academy…');
            $lifecycle->removeDemo($tenancy->withoutScoping(
                fn (): Tenant => Tenant::query()->where('slug', DemoAcademyBuilder::SLUG)->firstOrFail(),
            ));
        }

        $password = (string) ($this->option('password') ?: 'demo-password');
        $started = microtime(true);

        $result = $builder->build($password, fn (string $message) => $this->line($message));

        $this->newLine();
        $this->info(sprintf('Built %s in %.0f seconds.', $result['tenant']->name, microtime(true) - $started));
        $this->table(['', 'Count'], collect($result['counts'])
            ->map(fn (int $count, string $what) => [str_replace('_', ' ', ucfirst($what)), $count])
            ->values()->all());

        $this->newLine();
        $this->line('Sign in with any of these, password <comment>'.$password.'</comment>:');
        $this->table(['Who', 'Email', 'Role'], [
            ['Sunita Adhikari', DemoAcademyBuilder::OWNER_EMAIL, 'Owner (emailed sign-in code)'],
            ['Rajesh Shrestha', 'rajesh@himalayan-scholars.example', 'Management (emailed code)'],
            ['Anisha Maharjan', 'anisha@himalayan-scholars.example', 'Front desk, Baneshwor'],
            ['Bikash Thapa', 'bikash@himalayan-scholars.example', 'Teacher, Maths'],
            ['Pooja Gurung', 'pooja@himalayan-scholars.example', 'Teacher, Science'],
            ['Suman Karki', 'suman@himalayan-scholars.example', 'Teacher, English'],
            ['Nirmala Rai', 'nirmala@himalayan-scholars.example', 'Teacher, Computer'],
            ['Prakash Joshi', 'prakash@himalayan-scholars.example', 'Accountant (emailed code)'],
        ]);
        $this->line('Emailed codes appear in the API log while MAIL_MAILER=log (search for "sign-in code").');

        return self::SUCCESS;
    }
}
