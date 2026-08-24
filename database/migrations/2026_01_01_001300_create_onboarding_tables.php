<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presets, terminology and sample data.
 *
 * These three tables are what turn "fully configurable" from a liability into a feature. A product
 * where every vocabulary starts empty is unusable on day one; a preset fills them in, terminology
 * renames them to the customer's own words, and sample data lets someone see a finished report
 * before entering anything real (SL-PRD-000 §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminology_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // learner | guardian | batch | course | session | assessment | period
            $table->string('term_key', 32);
            $table->string('singular', 60);
            $table->string('plural', 60);
            $table->string('locale', 12)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'term_key', 'locale'], 'terminology_scope_unique');
        });

        Schema::create('preset_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('preset_code', 64);
            $table->unsignedInteger('version');
            // What the application actually created or skipped, so re-applying a newer version can
            // show a customer what would change before it changes it.
            $table->json('summary')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'preset_code']);
        });

        Schema::create('sample_data_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);

            // Every record created, by class and id.
            //
            // Removal deletes exactly what the load created rather than guessing from a name
            // pattern — which is the difference between a clean-up button and a button that
            // occasionally eats a real learner whose name happened to start with "Sample".
            $table->json('created');

            $table->foreignId('loaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('loaded_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'removed_at']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            // Derived state is computed from the data itself; this only records that a customer
            // has dismissed the checklist, which cannot be derived.
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('purge_after');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('onboarding_dismissed_at');
        });

        Schema::dropIfExists('sample_data_sets');
        Schema::dropIfExists('preset_applications');
        Schema::dropIfExists('terminology_overrides');
    }
};
