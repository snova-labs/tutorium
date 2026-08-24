<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment identity, webhook bookkeeping and the collection trail.
 *
 * Card details never appear here or anywhere else in the schema. What is stored is a reference to
 * a record the payment provider holds, which is the difference between a breach that costs a
 * customer their evening and one that costs them their card (SL-SEC-004 §7.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('legal_name', 190)->nullable();
            $table->string('billing_email', 190)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->json('address')->nullable();

            // A pointer into the provider's records — never a card number, never a token that
            // could be replayed.
            $table->string('provider', 16)->default('manual');
            $table->string('customer_ref', 190)->nullable();

            // Enough to show "•••• 4242, expires 09/2029" without holding anything sensitive.
            $table->string('method_brand', 32)->nullable();
            $table->string('method_last_four', 4)->nullable();
            $table->unsignedTinyInteger('method_exp_month')->nullable();
            $table->unsignedSmallInteger('method_exp_year')->nullable();

            // Institutions and academies without a company card pay against an invoice.
            $table->boolean('prefers_invoicing')->default(false);

            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('dunning_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('attempt');
            $table->timestamp('attempted_at')->useCurrent();
            // succeeded | failed | skipped
            $table->string('outcome', 16);
            $table->string('provider', 16);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id', 'attempt']);
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 16);
            // The provider's own id. Unique, because providers retry and a payment applied twice
            // is a worse failure than one applied late.
            $table->string('event_id', 190);
            $table->string('type', 64);
            $table->json('payload');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'type', 'processed_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            // A downgrade takes effect at the end of the period the customer has already paid for.
            $table->foreignId('pending_plan_id')->nullable()->after('plan_id')
                ->constrained('plans')->nullOnDelete();
            $table->date('pending_plan_starts_on')->nullable()->after('pending_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn('pending_plan_starts_on');
        });

        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('dunning_attempts');
        Schema::dropIfExists('billing_profiles');
    }
};
