<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learners, guardians and enrollments.
 *
 * The structural decision that everything downstream rests on: academic records attach to an
 * *enrollment*, never to a learner. A learner's history is the union of their enrollments, each
 * frozen to the batch context in which it happened — which is what makes transfers, repeats and
 * learners taking two courses at once behave correctly instead of approximately (SL-DAT-003 §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);
            // Terminal statuses stop new enrollments without deleting anything.
            $table->boolean('is_terminal')->default(false);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('enrollment_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);

            // This single flag is the entire definition of a billable learner (SL-BIL-006 §2.1).
            // It lives on a row the customer can see in their own settings rather than inside a
            // contract clause, which is what makes the metric arguable in their favour.
            $table->boolean('is_active_for_billing')->default(false);

            $table->boolean('is_terminal')->default(false);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('relation_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('learners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);

            // Stored whole, never split into first and last. That split loses Spanish double
            // surnames, family-name-first ordering and single-name cultures, and there is no way
            // to recover the original once it has been parsed wrongly (SL-LOC-005 §7).
            $table->string('legal_name', 190);
            $table->string('preferred_name', 120)->nullable();
            $table->string('sort_name', 190)->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('home_timezone', 64)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('photo_path')->nullable();

            $table->foreignId('status_id')->constrained('learner_statuses')->restrictOnDelete();
            $table->string('status_reason', 255)->nullable();
            $table->date('status_changed_on')->nullable();

            // Tenant-defined fields, so a language school can record a passport number and a
            // tutoring centre a school year without either carrying the other's columns.
            $table->json('custom')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status_id']);
            $table->index(['tenant_id', 'legal_name']);
        });

        Schema::create('guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 190);
            $table->foreignId('relation_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 190)->nullable();
            $table->string('secondary_email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('preferred_channel', 16)->default('email');
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('guardian_learner', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            // Report fan-out reads exactly this. A separated parent who should still receive
            // reports is a flag, not a duplicated learner record.
            $table->boolean('receives_reports')->default(true);
            $table->timestamps();

            $table->unique(['guardian_id', 'learner_id']);
            $table->index(['tenant_id', 'learner_id']);
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->string('number', 64);

            $table->date('enrolled_on');
            $table->foreignId('status_id')->constrained('enrollment_statuses')->restrictOnDelete();
            $table->string('status_reason', 255)->nullable();
            $table->date('ended_on')->nullable();

            // A transfer points forward to its successor, so a learner's path through the
            // institution can be walked without guessing.
            $table->foreignId('transferred_to_enrollment_id')->nullable()
                ->constrained('enrollments')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'learner_id', 'batch_id'], 'enrollments_learner_batch_unique');
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'batch_id', 'status_id']);
        });

        Schema::create('enrollment_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('enrollment_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('enrollment_statuses')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            // Metering backfills a missed day from this table rather than guessing, which is the
            // whole reason it exists as rows instead of a status column alone (SL-BIL-006 §3).
            $table->index(['tenant_id', 'changed_at']);
            $table->index(['enrollment_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_status_history');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('guardian_learner');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('learners');
        Schema::dropIfExists('relation_types');
        Schema::dropIfExists('enrollment_statuses');
        Schema::dropIfExists('learner_statuses');
    }
};
