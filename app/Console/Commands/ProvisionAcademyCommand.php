<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create an academy and its owner from the server, without the public signup.
 *
 * For a new environment: a real academy on staging, or the first customer on production. The owner
 * gets a random password, shown once; they sign in with it and the emailed code, then change it.
 */
final class ProvisionAcademyCommand extends Command
{
    protected $signature = 'platform:provision-academy
        {name : The academy\'s name}
        {owner_email : The owner\'s email address}
        {owner_name : The owner\'s full name}
        {--timezone=UTC : The first location\'s IANA timezone, e.g. Asia/Kathmandu}
        {--preset=blank : A preset code from config/presets.php}
        {--trial : Start as a trial instead of an active account}';

    protected $description = 'Create an academy with its owner, first brand and location';

    public function handle(TenantProvisioner $provisioner): int
    {
        $email = (string) $this->argument('owner_email');
        $timezone = (string) $this->option('timezone');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("[{$email}] is not an email address.");

            return self::INVALID;
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error("[{$timezone}] is not a timezone. Use a name such as Asia/Kathmandu.");

            return self::INVALID;
        }

        $password = Str::password(20);

        try {
            $result = $provisioner->provision([
                'name' => (string) $this->argument('name'),
                'owner_name' => (string) $this->argument('owner_name'),
                'owner_email' => $email,
                'password' => $password,
                'timezone' => $timezone,
                'preset_code' => (string) $this->option('preset'),
                'status' => $this->option('trial') ? Tenant::STATUS_TRIAL : Tenant::STATUS_ACTIVE,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error($messages[0]);
            }

            return self::FAILURE;
        }

        $this->info("Created {$result['tenant']->name} ({$result['tenant']->status}).");
        $this->line("Owner: {$email}");
        $this->line("Temporary password: {$password}");
        $this->warn('Shown once. The owner should sign in and change it.');

        return self::SUCCESS;
    }
}
