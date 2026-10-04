<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan changes, and invoices that can be split by them.
 *
 * With usage-based pricing a mid-period plan change cannot be handled by prorating a flat fee,
 * because there is no flat fee. The period is split at the change instead and each part is
 * metered against its own rate, which needs one invoice line per part (SL-BIL-006 §4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_at');
        });

        Schema::create('plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('direction', 16);
            $table->date('effective_on');
            // Null while a downgrade waits for its date.
            $table->timestamp('applied_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'applied_at', 'effective_on']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 190);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('quantity');
            // The day the quantity came from, so a customer can check it against their roster.
            $table->date('quantity_basis_date')->nullable();
            $table->foreignId('quantity_snapshot_id')->nullable()->constrained('usage_snapshots')->nullOnDelete();
            $table->unsignedInteger('unit_price_minor');
            $table->unsignedInteger('amount_minor');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('plan_changes');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('cancellation_reason');
        });
    }
};
