<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes emailed at sign-in, for operators and academy staff alike.
 *
 * Only a keyed hash of each code is kept, so a read of this table hands out nothing usable. A row
 * dies when it is used, when it expires, or when too many wrong guesses have been made against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_codes', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subject');
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_codes');
    }
};
