<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes, reports and delivery.
 *
 * The decision that shapes this whole table set: a report stores a **snapshot** of its own
 * numbers. Once a report has been sent, the figures a parent read must never silently change
 * because a teacher later corrected an unrelated grade. Regenerating produces a new report; it
 * does not rewrite the old one (SL-DAT-003 §3.6, FR-RPT-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);
            // Whether notes of this kind default to appearing on reports. A teacher can override
            // per note, but the default is what protects a category like Behaviour from leaking.
            $table->boolean('report_visible_default')->default(false);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('teacher_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporting_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('note_category_id')->constrained()->restrictOnDelete();

            $table->text('body');

            // Explicit rather than inferred. A note about a safeguarding conversation is not a
            // paragraph that should be able to reach a parent by accident.
            $table->boolean('is_report_visible')->default(false);

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'enrollment_id', 'reporting_period_id'], 'notes_enrollment_period_index');
        });

        Schema::create('report_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);

            // Which sections appear, and in what order. A kids report and an adult certification
            // report differ by this column rather than by a code branch (FR-RPT-2).
            $table->json('blocks');

            $table->string('locale', 12)->nullable();
            $table->string('closing', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporting_period_id')->constrained()->cascadeOnDelete();
            $table->json('options')->nullable();

            // queued | running | completed | completed_with_failures | failed
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('failures')->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id', 'status']);
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_run_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 64);
            $table->string('file_path')->nullable();
            $table->string('file_disk', 32)->nullable();

            // The numbers as sent. Never recomputed on read — this column is the evidence.
            $table->json('stats_snapshot');

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'enrollment_id', 'reporting_period_id'], 'reports_enrollment_period_index');
        });

        Schema::create('report_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();

            // guardian | learner | sponsor — one row per recipient, so "the other parent never
            // got it" is answerable with evidence rather than opinion.
            $table->string('recipient_type', 16);
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_name', 190)->nullable();
            $table->string('to_address', 190);

            $table->string('channel', 16)->default('email');
            // queued | sent | failed | bounced
            $table->string('status', 16)->default('queued');
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref', 190)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['report_id', 'status']);
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('subject', 190);
            $table->text('body_html');
            $table->json('variables')->nullable();
            $table->string('locale', 12)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'brand_id', 'code'], 'email_templates_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_templates');
        Schema::dropIfExists('teacher_notes');
        Schema::dropIfExists('note_categories');
    }
};
