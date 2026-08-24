<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The control plane's own identity and its access trail.
 *
 * Operators are a separate table from tenant users on purpose. Sharing one would mean a bug in
 * tenant authentication could reach the platform, and it would make "who can see everything"
 * a flag on a row rather than a different door (SL-SEC-004 §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password');

            // Not nullable-by-convention but genuinely required: an operator without two-factor
            // cannot complete sign-in, because this account can reach every customer's data.
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('impersonations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Required, and copied verbatim into the tenant's own activity log. A reason nobody
            // writes down is a reason nobody can question later.
            $table->string('reason', 500);

            $table->timestamp('started_at')->useCurrent();
            // Time-limited by default. Support access that stays open is access nobody revoked.
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['operator_id', 'started_at']);
        });

        Schema::create('tenant_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // queued | running | ready | failed
            $table->string('status', 16)->default('queued');
            $table->string('file_path')->nullable();
            $table->string('file_disk', 32)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();

            // Either a tenant user asked for their own data, or an operator produced it before a
            // purge. Both are recorded.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');
        Schema::dropIfExists('impersonations');
        Schema::dropIfExists('operators');
    }
};
