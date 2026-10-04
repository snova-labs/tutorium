<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spreadsheet imports: checked first, written only on commit (FR-PPL-5).
 *
 * The uploaded file is kept until the import is committed or discarded, and re-read at commit, so
 * what is written is exactly what was previewed against the data as it stands at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 16);
            $table->string('original_name', 190);
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->json('totals')->nullable();
            $table->json('issues')->nullable();
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
