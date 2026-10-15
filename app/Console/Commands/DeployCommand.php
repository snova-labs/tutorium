<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Everything a container does on the way up, in one command.
 *
 * The stack's one-off `migrate` service runs this and nothing else, so what a deploy does is
 * readable here rather than assembled out of shell in a compose file:
 *
 *   1. migrations, always;
 *   2. the first academy, when FIRST_RUN_ACADEMY says so and it does not exist yet;
 *   3. the demo academies, when SEED_DEMO=true.
 *
 * Steps 2 and 3 are flags because an environment needs them once and a deploy happens every week.
 * Both do nothing when what they would create is already there, so this is safe to run every time —
 * which is the only reason it can live in the deploy at all.
 *
 * Migrations failing stops the deploy: the app, queue and scheduler wait on this container, and a
 * half-migrated database serving traffic is worse than a stack that refused to start. The two
 * seeding steps are reported and forgiven, because a mistyped academy name is not a reason to take
 * an environment down.
 */
final class DeployCommand extends Command
{
    protected $signature = 'platform:deploy';

    protected $description = 'Migrate, then create the first academy and demo data if asked to';

    public function handle(): int
    {
        $this->components->info('Migrations');

        if ($this->call('migrate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            $this->error('Migrations failed. Nothing else ran, and the stack will not start on a half-migrated database.');

            return self::FAILURE;
        }

        $this->components->info('First academy');
        $this->call('platform:first-run', ['--no-fail' => true]);

        if (config('demo.seed_on_deploy') === true) {
            $this->components->info('Demo academies (SEED_DEMO=true)');
            // Refuses on production by itself, and does nothing when they already exist.
            $this->call('platform:demo');
            $this->comment('Set SEED_DEMO=false once you have them: building them is minutes the next deploy need not spend.');
        }

        return self::SUCCESS;
    }
}
