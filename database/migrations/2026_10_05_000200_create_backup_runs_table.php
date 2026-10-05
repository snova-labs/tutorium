<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every backup and every restore drill (SL-415).
 *
 * A backup nobody has restored is a hope. Each drill's outcome is kept next to the backup it
 * restored, so "when did we last prove we can get the data back" has a one-query answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16);      // backup | drill
            $table->string('status', 16);    // running | ok | failed
            $table->string('name', 64)->nullable();   // the backup folder
            $table->unsignedBigInteger('bytes')->nullable();
            $table->json('details')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
