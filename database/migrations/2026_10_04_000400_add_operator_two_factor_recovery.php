<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What operator two-factor needs beyond the secret.
 *
 * Recovery codes are stored hashed, so a database read does not hand out a way in. The last step
 * used is what stops a code being replayed within its thirty seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_recovery_codes', 'two_factor_last_step']);
        });
    }
};
