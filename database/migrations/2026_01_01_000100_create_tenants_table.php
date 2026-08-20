<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('region_code', 16)->default('default');
            $table->string('preset_code', 64)->nullable();
            $table->string('status', 24)->default('trial')->index();
            $table->string('deployment_mode', 16)->default('cloud');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('locale', 12)->default('en');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
