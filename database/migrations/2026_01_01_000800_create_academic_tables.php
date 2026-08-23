<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic structure: courses, batches, timetables, sessions, holidays and reporting periods.
 *
 * One deviation from SL-DAT-003 §5, recorded here so it is not mistaken for drift: the design
 * calls the class-occurrence table `sessions`, but that name is already taken by the framework's
 * database session driver. It is `class_sessions` here. `ClassSession::query()` also reads
 * unambiguously next to the session facade, which is worth something on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // NULL course means the type is available to every course in the account.
            $table->foreignId('course_id')->nullable();
            $table->string('name', 60);
            $table->string('code', 32);
            // A make-up class is recorded but must not inflate a term's attendance percentage.
            $table->boolean('counts_in_attendance')->default(true);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 32);
            $table->string('audience', 32)->default('kids');
            $table->text('description')->nullable();
            // monthly | term | quarter | block | custom
            $table->string('period_type', 16)->default('monthly');
            // Quarter anchor and block length, used only by the matching period type.
            $table->unsignedTinyInteger('period_anchor_month')->default(1);
            $table->unsignedSmallInteger('period_block_weeks')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'brand_id', 'is_active']);
        });

        Schema::create('batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 32);

            // Inherited from the branch, overridable for online cohorts taught across borders.
            // This column is authoritative for every session, due date and period boundary below.
            $table->string('timezone', 64);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('delivery_mode', 16)->default('in_person');
            $table->string('status', 16)->default('planned');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'course_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('batch_teacher', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('lead');
            $table->timestamps();

            $table->unique(['batch_id', 'user_id']);
        });

        Schema::create('timetable_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_type_id')->constrained()->restrictOnDelete();

            // ISO-8601: Monday is 1. See App\Enums\Weekday for why this is not the branch's
            // week_start setting.
            $table->unsignedTinyInteger('weekday');
            // Wall-clock time in the batch timezone. The instant is derived per occurrence.
            $table->time('start_time_local');
            $table->unsignedSmallInteger('duration_min')->default(60);

            // A mid-term schedule change retires a slot and adds another, rather than editing
            // history that has already been taught.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id', 'weekday']);
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 120);
            $table->boolean('blocks_sessions')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('class_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_type_id')->constrained()->restrictOnDelete();

            // Both representations are stored. The local date is what a person agreed to and must
            // never drift; the UTC instant is what every calculation uses (SL-ARC-002 §12).
            $table->date('session_local_date');
            $table->time('start_time_local');
            $table->timestamp('starts_at_utc');
            $table->timestamp('ends_at_utc');

            $table->string('status', 16)->default('scheduled');
            $table->string('cancel_reason', 255)->nullable();
            $table->string('meeting_url', 512)->nullable();
            $table->string('meeting_provider_ref', 190)->nullable();

            $table->foreignId('generated_from_slot_id')->nullable()
                ->constrained('timetable_slots')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Idempotent generation: re-running for the same range cannot duplicate a slot's
            // occurrence. Ad-hoc sessions carry a NULL slot, which MySQL treats as distinct, so
            // they are never blocked by this.
            $table->unique(['batch_id', 'generated_from_slot_id', 'session_local_date'], 'class_sessions_slot_date_unique');
            $table->index(['tenant_id', 'batch_id', 'session_local_date']);
            $table->index(['tenant_id', 'starts_at_utc']);
        });

        Schema::create('reporting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // A period belongs to a batch where one exists, otherwise to the course as a template.
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type', 16);
            $table->string('label', 60);
            $table->date('starts_local_date');
            $table->date('ends_local_date');
            // open | closed | reported — closing stops late edits from silently changing a report
            // that has already been sent.
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            //
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'batch_id', 'label'], 'reporting_periods_batch_label_unique');
            $table->index(['tenant_id', 'course_id', 'starts_local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_periods');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('timetable_slots');
        Schema::dropIfExists('batch_teacher');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('session_types');
    }
};