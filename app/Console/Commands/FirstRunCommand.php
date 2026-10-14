<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create the first academy from the stack's own environment variables.
 *
 * A fresh environment is a chicken and egg: the only way to make an academy is to be signed in,
 * and the only way to sign in is to have one. `platform:provision-academy` solves it from a
 * terminal, which means a clone of this repository and a typed command on the server. This solves
 * it from the stack: set FIRST_RUN_ACADEMY and friends in Portainer, redeploy, and the migrate step
 * leaves a working academy behind.
 *
 * Safe to run on every deploy, which is the point:
 *   - nothing configured  → says so and exits 0
 *   - academy exists      → says so and exits 0
 *   - anything else       → one academy, owner included, exactly as the console command makes it
 */
final class FirstRunCommand extends Command
{
    protected $signature = 'platform:first-run
        {--no-fail : Report problems but still exit 0, so a bad variable never blocks a deploy}';

    protected $description = 'Create the first academy from FIRST_RUN_* environment variables, once';

    public function handle(TenantProvisioner $provisioner, TenantContext $tenancy): int
    {
        /** @var array{academy: string, owner_name: string, owner_email: string, password: string, timezone: string, preset: string} $settings */
        $settings = array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('platform.first_run'),
        );

        if ($settings['academy'] === '') {
            $this->line('FIRST_RUN_ACADEMY is not set, so there is no first academy to create. Nothing to do.');

            return self::SUCCESS;
        }

        $existing = $tenancy->withoutScoping(
            fn () => Tenant::query()->where('name', $settings['academy'])->first(),
        );

        if ($existing !== null) {
            $this->line("[{$existing->name}] already exists. Nothing to do.");

            return self::SUCCESS;
        }

        foreach (['owner_name', 'owner_email'] as $needed) {
            if ($settings[$needed] === '') {
                return $this->refuse('Set FIRST_RUN_OWNER_NAME and FIRST_RUN_OWNER_EMAIL as well: an academy with no owner is nobody\'s.');
            }
        }

        if (filter_var($settings['owner_email'], FILTER_VALIDATE_EMAIL) === false) {
            return $this->refuse("FIRST_RUN_OWNER_EMAIL [{$settings['owner_email']}] is not an email address.");
        }

        if (! in_array($settings['timezone'], timezone_identifiers_list(), true)) {
            return $this->refuse("FIRST_RUN_TIMEZONE [{$settings['timezone']}] is not a timezone. Use a name such as Asia/Kathmandu.");
        }

        // A password nobody chose is better than a password everybody knows, and this log line is
        // the only place it is ever shown.
        $chosen = $settings['password'] !== '';
        $password = $chosen ? $settings['password'] : Str::password(20);

        try {
            $result = $provisioner->provision([
                'name' => $settings['academy'],
                'owner_name' => $settings['owner_name'],
                'owner_email' => $settings['owner_email'],
                'password' => $password,
                'timezone' => $settings['timezone'],
                'preset_code' => $settings['preset'] !== '' ? $settings['preset'] : 'blank',
                'status' => Tenant::STATUS_ACTIVE,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error($messages[0]);
            }

            return $this->option('no-fail') ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Created {$result['tenant']->name}.");
        $this->line("Owner: {$settings['owner_email']}");

        if ($chosen) {
            $this->line('Password: the one in FIRST_RUN_PASSWORD.');
        } else {
            $this->line("Temporary password: {$password}");
            $this->warn('Shown once, here in this log. The owner should sign in and change it.');
        }

        $this->line('Clear FIRST_RUN_PASSWORD from the stack once the owner has signed in.');

        return self::SUCCESS;
    }

    /** Loud in the log either way; fatal only when the caller wants a failed deploy. */
    private function refuse(string $message): int
    {
        $this->error($message);

        return $this->option('no-fail') ? self::SUCCESS : self::INVALID;
    }
}
