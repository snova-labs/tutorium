<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance: the status vocabulary, the policy that interprets it, and the marks themselves.
 *
 * The separation matters. A mark records what happened; the policy decides what it *means*. The
 * same "Late" mark counts as attendance in a batch that allows late joining and does not in one
 * that doesn't — and switching that policy must never rewrite the marks a teacher made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);

            // Numerator: does this mark mean the learner was there.
            $table->boolean('counts_as_attended')->default(false);

            // Denominator: does this session count as an opportunity at all. Excused leaves the
            // denominator entirely — a learner excused from a class is not scored zero for it.
            $table->boolean('counts_in_rate')->default(true);

            // Late-like marks count as attendance only where the policy allows late joining. This
            // flag is what lets one switch change the meaning of existing marks without editing
            // a single one of them.
            $table->boolean('is_late')->default(false);

            // Display and risk flags — the at-risk list reads this, not the colour.
            $table->boolean('is_negative')->default(false);

            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // course | batch. Resolution walks batch → course → system defaults, and the level
            // that answered is reported back so a screen can show where a value came from.
            $table->string('scope_type', 16);
            $table->unsignedBigInteger('scope_id');

            // When false, attendance is recorded and displayed but never penalises anyone: no
            // at-risk flag, no engagement effect. Some academies track it purely for safeguarding.
            $table->boolean('is_compulsory')->default(true);

            $table->boolean('allow_late_join')->default(true);
            $table->unsignedSmallInteger('late_grace_min')->default(10);

            // NULL means every type counts. A list restricts it — a make-up class recorded but
            // excluded from the headline figure is the common case.
            $table->json('counted_session_type_ids')->nullable();

            $table->unsignedTinyInteger('low_threshold_pct')->default(75);
            $table->timestamps();

            $table->unique(['tenant_id', 'scope_type', 'scope_id'], 'attendance_policies_scope_unique');
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained('attendance_statuses')->restrictOnDelete();

            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->string('note', 500)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            // Re-submitting a register updates the marks rather than duplicating them. A teacher
            // pressing save twice is normal behaviour, not an error to guard against.
            $table->unique(['class_session_id', 'enrollment_id'], 'attendance_session_enrollment_unique');
            $table->index(['tenant_id', 'enrollment_id']);
            $table->index(['tenant_id', 'class_session_id', 'status_id']);
        });

        Schema::create('makeup_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('missed_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('makeup_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'missed_session_id'], 'makeup_enrollment_missed_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('makeup_links');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_policies');
        Schema::dropIfExists('attendance_statuses');
    }
};
