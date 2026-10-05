<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signup waits for the address to be confirmed before anything is provisioned (SL-402).
 *
 * The form's answers sit here, encrypted, until the emailed link is used. Nothing else exists until
 * then: no account, no owner, no trial clock. A row is deleted the moment it is confirmed, and an
 * unconfirmed one is purged soon after its link expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_signups', function (Blueprint $table): void {
            $table->id();
            // One open signup per address: a second submission replaces the first and its link.
            $table->string('email', 190)->unique();

            // Only a hash. A leaked table must not contain working links.
            $table->string('token_hash', 64)->unique();

            // The rest of the form, encrypted. The password inside is already a hash.
            $table->text('payload');

            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::table('signup_attempts', function (Blueprint $table): void {
            // Throttling by email domain as well as by address and network (SL-401).
            $table->string('domain', 190)->nullable()->after('email');
            $table->index(['domain', 'attempted_at']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            // ISO 3166-1 alpha-2, from the signup form.
            $table->char('country', 2)->nullable()->after('region_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('country');
        });

        Schema::table('signup_attempts', function (Blueprint $table): void {
            $table->dropIndex(['domain', 'attempted_at']);
            $table->dropColumn('domain');
        });

        Schema::dropIfExists('pending_signups');
    }
};
