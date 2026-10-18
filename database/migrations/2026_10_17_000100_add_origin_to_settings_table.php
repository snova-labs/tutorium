<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a setting's value came from, as opposed to which level answered.
 *
 * `scope_type` already says which level supplied a value (batch, course, branch, brand, tenant).
 * It cannot say whether a tenant-level value was chosen by the academy or written by the preset
 * when the account was created — a preset writes at tenant scope like everything else. SL-ARC-002
 * §7 asks the resolver to mark a value as having come from a preset, and a screen that cannot tell
 * "we picked this for you" from "you picked this" is the reason settings go unexamined for years.
 *
 * Null means unknown: every row written before this migration, which is the honest answer for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('origin', 16)->nullable()->after('scope_id');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
