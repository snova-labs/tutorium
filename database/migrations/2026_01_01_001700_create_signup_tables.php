<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve signup, email verification and staff invitations.
 *
 * The design decision worth stating here: verification gates **outbound email**, not the product.
 * Someone who has just signed up can build their timetable and take a register immediately; what
 * they cannot do is send anything to a parent from an address nobody has proved they own. That
 * stops the abuse the check exists for without punishing the customer it was meant to protect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('name', 120)->nullable();

            // Only a hash is stored. A leaked database should not hand an attacker a set of
            // working invitations into other people's accounts.
            $table->string('token_hash', 64)->unique();

            $table->string('role_name', 120);
            $table->boolean('scope_all_branches')->default(false);
            $table->json('branch_ids')->nullable();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->timestamps();

            // One open invitation per address per account. Re-inviting resends rather than
            // stacking up several working tokens for the same person.
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'accepted_at']);
        });

        Schema::create('signup_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 190);
            $table->string('ip', 45)->nullable();
            $table->string('outcome', 24);
            $table->string('reason', 190)->nullable();
            $table->timestamp('attempted_at')->useCurrent();
            $table->timestamps();

            // Rate limiting reads these. Kept short and purged, because a log of who tried to sign
            // up is not something worth holding indefinitely.
            $table->index(['email', 'attempted_at']);
            $table->index(['ip', 'attempted_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'verification_token_hash')) {
                $table->string('verification_token_hash', 64)->nullable()->after('email_verified_at');
                $table->timestamp('verification_sent_at')->nullable()->after('verification_token_hash');
            }
        });

        Schema::table('tenants', function (Blueprint $table): void {
            // Which reminders have gone out, so a customer is never sent the same warning twice
            // and never sent one after they have already paid.
            $table->json('trial_reminders_sent')->nullable()->after('trial_ends_at');
            $table->timestamp('trial_expired_at')->nullable()->after('trial_reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['trial_reminders_sent', 'trial_expired_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['verification_token_hash', 'verification_sent_at']);
        });

        Schema::dropIfExists('signup_attempts');
        Schema::dropIfExists('invitations');
    }
};
