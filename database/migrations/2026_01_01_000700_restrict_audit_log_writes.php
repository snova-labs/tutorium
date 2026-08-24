<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revokes UPDATE and DELETE on audit_logs from the application's own database account.
 *
 * The model guard is a convenience; this is the control. An append-only log that the application
 * could rewrite is not evidence (SL-SEC-004 �12).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return; // dev and test share one account; enforced in production only
        }

        $user = config('database.connections.mysql.username');
        $db = config('database.connections.mysql.database');

        DB::statement("REVOKE UPDATE, DELETE ON `{$db}`.`audit_logs` FROM '{$user}'@'%'");
    }

    public function down(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $user = config('database.connections.mysql.username');
        $db = config('database.connections.mysql.database');

        DB::statement("GRANT UPDATE, DELETE ON `{$db}`.`audit_logs` TO '{$user}'@'%'");
    }
};
