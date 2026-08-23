<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // user | operator | system — operator access is recorded in the tenant's own log
            // so customers can see when we looked (SL-SEC-004 §6).
            $table->string('actor_type', 16)->default('user');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            $table->string('module', 48);
            $table->string('action', 32);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('target_label')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            // $table->timestamps(); // not needed, we have occurred_at

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['tenant_id', 'module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
