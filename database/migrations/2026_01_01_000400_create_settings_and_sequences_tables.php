<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->json('value')->nullable();

            // tenant | brand | branch | course | batch
            $table->string('scope_type', 16)->default('tenant');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'scope_type', 'scope_id'], 'settings_scope_unique');
            $table->index(['tenant_id', 'key']);
        });

        Schema::create('id_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // learner | enrollment | assessment | report | invoice | ...
            $table->string('entity', 48);
            $table->string('scope_type', 16)->default('tenant');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->string('prefix', 32)->default('');
            $table->string('separator', 4)->default('-');
            $table->unsignedTinyInteger('pad_width')->default(4);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity', 'scope_type', 'scope_id'], 'sequences_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
        Schema::dropIfExists('settings');
    }
};
