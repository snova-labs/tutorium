<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * A seeder that may run without a console.
 *
 * Tenant provisioning calls these seeders directly, so there may be no command to write progress
 * to. The framework documents $command as always set, which is not true on that path.
 */
abstract class ConsoleAwareSeeder extends Seeder
{
    /** @param 'info'|'warn'|'newLine' $level */
    protected function say(string $level, string $message = ''): void
    {
        // Read untyped and narrowed, because the declared type promises a command that a direct
        // call (provisioning) never sets.
        $command = get_object_vars($this)['command'] ?? null;

        if (! $command instanceof Command) {
            return;
        }

        match ($level) {
            'info' => $command->info($message),
            'warn' => $command->warn($message),
            'newLine' => $command->newLine(),
        };
    }
}
