<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('logo_path')->nullable();
            $table->json('colors')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('domain')->nullable();
            $table->string('locale', 12)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Uniqueness is per tenant, never global.
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 32);

            // IANA identifier. Everything scheduled here is defined in this zone
            // and stored in UTC (SL-LOC-005 §2).
            $table->string('timezone', 64)->default('UTC');
            $table->string('week_start', 12)->default('monday');
            $table->json('weekend_days')->nullable();

            $table->json('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('brands');
    }
};
