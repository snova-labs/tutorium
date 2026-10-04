<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NULL means "inherit" in an attendance policy.
 *
 * The resolver walks batch → course → settings and stops at the first level with a value, but the
 * columns were created with defaults, so a batch policy that only changed the grace period also
 * silently overrode every other setting with the column default. Values a row does not set are
 * now NULL, and the next level answers.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private array $defaults = [
        'is_compulsory' => 1,
        'allow_late_join' => 1,
        'late_grace_min' => 10,
        'low_threshold_pct' => 75,
    ];

    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->boolean('is_compulsory')->nullable()->default(null)->change();
            $table->boolean('allow_late_join')->nullable()->default(null)->change();
            $table->unsignedSmallInteger('late_grace_min')->nullable()->default(null)->change();
            $table->unsignedTinyInteger('low_threshold_pct')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        foreach ($this->defaults as $column => $value) {
            DB::table('attendance_policies')->whereNull($column)->update([$column => $value]);
        }

        Schema::table('attendance_policies', function (Blueprint $table): void {
            $table->boolean('is_compulsory')->default(true)->change();
            $table->boolean('allow_late_join')->default(true)->change();
            $table->unsignedSmallInteger('late_grace_min')->default(10)->change();
            $table->unsignedTinyInteger('low_threshold_pct')->default(75)->change();
        });
    }
};
