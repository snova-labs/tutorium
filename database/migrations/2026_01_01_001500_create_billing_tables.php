<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans, entitlements, metering and invoices.
 *
 * The load-bearing decision is `usage_snapshots`: an immutable daily count that every invoice is
 * built from. Billing never reads a live number, because a live number cannot be reconstructed
 * three months later when a customer asks how a figure was arrived at (SL-BIL-006 §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name', 120);
            $table->string('currency', 3)->default('EUR');
            // Stored in minor units. Floating-point money is a defect waiting for a rounding edge.
            $table->unsignedInteger('unit_price_minor')->default(0);
            $table->unsignedInteger('minimum_charge_minor')->default(0);
            $table->string('interval', 16)->default('month');
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            // Booleans, integers and JSON all live here; the reader knows what it asked for.
            $table->json('value');
            // soft — warn and allow. hard — block the action that would exceed it.
            $table->string('enforcement', 8)->default('soft');
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('tenant_entitlement_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->json('value');
            // Required. A negotiated deal nobody can explain later becomes a dispute.
            $table->string('reason', 255);
            $table->foreignId('granted_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'feature_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // stripe | mor | manual
            $table->string('provider', 16)->default('manual');
            $table->string('provider_ref', 190)->nullable();
            // trialing | active | past_due | cancelled
            $table->string('status', 16)->default('active');
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('active_learners');
            // Per brand and branch, so a customer querying a figure can see where it came from.
            $table->json('breakdown')->nullable();

            // Corrections write a new row rather than editing this one. Both stay visible, and the
            // invoice says which was used — the alternative is a number that changed and a
            // customer who is asked to take our word for it (FR-MTR-4).
            $table->boolean('is_correction')->default(false);
            $table->foreignId('corrects_snapshot_id')->nullable()->constrained('usage_snapshots')->nullOnDelete();
            $table->string('correction_reason', 255)->nullable();
            $table->foreignId('corrected_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();

            $table->timestamps();

            // One original per day. Corrections are flagged and therefore excluded from this.
            $table->unique(['tenant_id', 'snapshot_date', 'is_correction'], 'usage_snapshots_day_unique');
            $table->index(['tenant_id', 'snapshot_date']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 64);

            $table->date('period_start');
            $table->date('period_end');

            // The metered quantity and, beside it, exactly where it came from.
            $table->unsignedInteger('quantity');
            $table->date('quantity_basis_date')->nullable();
            $table->foreignId('quantity_snapshot_id')->nullable()->constrained('usage_snapshots')->nullOnDelete();

            $table->string('currency', 3);
            $table->unsignedInteger('unit_price_minor');
            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('minimum_adjustment_minor')->default(0);
            $table->unsignedInteger('tax_minor')->default(0);
            $table->unsignedInteger('total_minor');
            $table->string('tax_note', 190)->nullable();

            // draft | issued | paid | void
            $table->string('status', 16)->default('draft');
            $table->string('provider', 16)->nullable();
            $table->string('provider_ref', 190)->nullable();
            $table->json('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('usage_snapshots');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('tenant_entitlement_overrides');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
