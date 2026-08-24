<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessments and grading.
 *
 * This replaces the fixed "homework out of a hundred" model entirely. Two structural decisions do
 * the work: assessment types and grading schemes are rows a tenant edits, and every graded result
 * carries a normalised 0–100 value so that a rubric, a points score and a pass/fail can sit in one
 * column and be averaged honestly (SL-DAT-003 §3.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable();
            $table->string('name', 60);
            $table->string('code', 32);
            // Whether work of this type appears in the "submitted X of Y" figure on a report.
            $table->boolean('counts_in_submission_rate')->default(true);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('grading_schemes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 32);
            // points | percentage | pass_fail | letter | level | rubric
            $table->string('kind', 16);
            // Everything the kind needs: a maximum, letter bands, a level ladder, a direction.
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('submission_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);
            $table->boolean('counts_as_submitted')->default(true);

            // Exempt work leaves the denominator; Missing work scores zero and stays in it. That
            // distinction is a teacher's judgement about a month, not a system default.
            $table->boolean('excluded_from_average')->default(false);

            $table->boolean('is_negative')->default(false);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('grading_scheme_id')->constrained()->restrictOnDelete();

            $table->string('number', 64);
            $table->string('title', 190);
            $table->text('description')->nullable();

            $table->date('assigned_local_date')->nullable();
            // Both forms again: the date a person agreed to, and the instant everything computes on.
            $table->date('due_local_date')->nullable();
            $table->timestamp('due_at_utc')->nullable();

            // Denormalised from the scheme so a points grid does not join for every cell.
            $table->decimal('max_points', 8, 2)->nullable();

            // Draft assessments are invisible to the gradebook until published, so a half-written
            // rubric never appears in front of a class.
            $table->boolean('is_published')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'batch_id', 'due_local_date']);
        });

        Schema::create('rubric_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 190);
            $table->text('descriptor')->nullable();
            $table->decimal('max_points', 8, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'assessment_id']);
        });

        Schema::create('grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('submission_status_id')->constrained()->restrictOnDelete();

            // The raw result, in whatever terms the scheme uses.
            $table->decimal('raw_score', 8, 2)->nullable();
            $table->string('letter', 8)->nullable();
            $table->string('level_code', 16)->nullable();
            $table->boolean('passed')->nullable();

            // The one column every dashboard, trend and report reads. Written by the scheme's own
            // strategy, so adding a seventh scheme never touches analytics code.
            $table->decimal('normalized_pct', 5, 2)->nullable();

            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'enrollment_id'], 'grades_assessment_enrollment_unique');
            $table->index(['tenant_id', 'enrollment_id']);
        });

        Schema::create('grade_rubric_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rubric_criterion_id')->constrained()->cascadeOnDelete();
            $table->decimal('points', 8, 2);
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->unique(['grade_id', 'rubric_criterion_id'], 'rubric_scores_grade_criterion_unique');
        });

        Schema::create('type_weights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight_pct', 5, 2);
            $table->timestamps();

            $table->unique(['course_id', 'assessment_type_id'], 'type_weights_course_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_weights');
        Schema::dropIfExists('grade_rubric_scores');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('submission_statuses');
        Schema::dropIfExists('grading_schemes');
        Schema::dropIfExists('assessment_types');
    }
};
