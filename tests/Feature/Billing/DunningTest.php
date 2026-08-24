<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\BillingProfile;
use App\Models\DunningAttempt;
use App\Models\IdSequence;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Services\DunningService;
use App\Services\TenantExportService;
use App\Services\TenantProvisioner;
use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Payments\PaymentResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens when a customer cannot pay.
 *
 * The rule these tests protect: restricting service is not the same as withholding data. An
 * academy that cannot pay this month still has parents expecting reports and inspectors expecting
 * records (SL-BIL-006 §6).
 */
final class DunningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Invoice $invoice;

    private FakePaymentProvider $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = new FakePaymentProvider;
        $this->app->instance(PaymentProvider::class, $this->payments);

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
            'status' => Tenant::STATUS_ACTIVE,
        ])['tenant'];

        $plan = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 4000,
        ]);

        app(TenantContext::class)->runAs($this->tenant, function () use ($plan): void {
            IdSequence::query()->firstOrCreate(
                ['entity' => 'invoice', 'scope_type' => 'tenant', 'scope_id' => null],
                ['prefix' => 'INV', 'separator' => '-', 'pad_width' => 5, 'next_number' => 1, 'is_active' => true],
            );

            Subscription::query()->create([
                'plan_id' => $plan->getKey(),
                'status' => Subscription::ACTIVE,
                'current_period_start' => now()->startOfMonth()->toDateString(),
                'current_period_end' => now()->endOfMonth()->toDateString(),
            ]);

            BillingProfile::query()->create([
                'provider' => 'fake',
                'customer_ref' => 'fake_cus_1',
                'method_brand' => 'Visa',
                'method_last_four' => '4242',
                'method_exp_month' => 9,
                'method_exp_year' => 2029,
            ]);

            $this->invoice = Invoice::query()->create([
                'number' => 'INV-00001',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'quantity' => 184,
                'currency' => 'EUR',
                'unit_price_minor' => 200,
                'subtotal_minor' => 36_800,
                'total_minor' => 36_800,
                'status' => Invoice::ISSUED,
                'issued_at' => now(),
            ]);
        });
    }

    #[Test]
    public function a_successful_charge_settles_the_invoice(): void
    {
        $this->payments->willSucceed('ref-1');

        $attempt = app(DunningService::class)->attempt($this->tenant, $this->invoice);

        $this->assertSame(DunningAttempt::SUCCEEDED, $attempt->outcome);
        $this->assertSame(Invoice::PAID, $this->invoice->refresh()->status);
        $this->assertSame('ref-1', $this->invoice->provider_ref);
    }

    #[Test]
    public function a_first_failure_leaves_the_account_working(): void
    {
        $this->payments->willFail('insufficient_funds', 'Insufficient funds');

        app(DunningService::class)->attempt($this->tenant, $this->invoice);

        $tenant = $this->tenant->refresh();

        // Past due, and still entirely usable. The banner does the work, not a lockout.
        $this->assertSame(Tenant::STATUS_PAST_DUE, $tenant->status);
        $this->assertTrue($tenant->isOperational());
    }

    #[Test]
    public function four_failures_are_attempted_before_anything_is_restricted(): void
    {
        $this->payments->willFail('insufficient_funds', 'Insufficient funds');

        foreach (range(1, 3) as $ignored) {
            app(DunningService::class)->attempt($this->tenant, $this->invoice);
            $this->assertNotSame(Tenant::STATUS_SUSPENDED, $this->tenant->refresh()->status);
        }

        app(DunningService::class)->attempt($this->tenant, $this->invoice);

        $this->assertSame(Tenant::STATUS_SUSPENDED, $this->tenant->refresh()->status);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(4, DunningAttempt::query()->where('outcome', DunningAttempt::FAILED)->count());
        });
    }

    #[Test]
    public function a_permanently_dead_card_is_not_retried_four_times(): void
    {
        $this->payments->willFail('account_closed', 'The account has been closed');

        app(DunningService::class)->attempt($this->tenant, $this->invoice);

        // Retrying a closed account four more times only annoys the customer.
        $this->assertSame(Tenant::STATUS_SUSPENDED, $this->tenant->refresh()->status);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertNull(DunningAttempt::query()->latest('id')->first()->next_attempt_at);
        });
    }

    #[Test]
    public function suspension_is_read_only_and_export_still_works(): void
    {
        $this->payments->willFail('card_declined', 'Declined');

        foreach (range(1, 4) as $ignored) {
            app(DunningService::class)->attempt($this->tenant, $this->invoice);
        }

        $tenant = $this->tenant->refresh();
        $this->assertTrue($tenant->isSuspended());

        // Their records are theirs, whatever they owe us.
        $export = app(TenantExportService::class)->request($tenant);
        $this->assertSame(TenantExport::STATUS_QUEUED, $export->status);
    }

    #[Test]
    public function payment_after_suspension_restores_everything_at_once(): void
    {
        $this->payments->queue(
            PaymentResult::failed('fake', 'card_declined', 'Declined'),
            PaymentResult::failed('fake', 'card_declined', 'Declined'),
            PaymentResult::failed('fake', 'card_declined', 'Declined'),
            PaymentResult::failed('fake', 'card_declined', 'Declined'),
            PaymentResult::succeeded('fake', 'ref-recovered'),
        );

        foreach (range(1, 4) as $ignored) {
            app(DunningService::class)->attempt($this->tenant, $this->invoice);
        }

        $this->assertSame(Tenant::STATUS_SUSPENDED, $this->tenant->refresh()->status);

        app(DunningService::class)->attempt($this->tenant, $this->invoice->refresh());

        // Nothing was removed, so nothing has to be rebuilt.
        $tenant = $this->tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNull($tenant->suspended_at);
        $this->assertSame(Invoice::PAID, $this->invoice->refresh()->status);
    }

    #[Test]
    public function the_customer_can_read_why_in_their_own_activity_log(): void
    {
        $this->payments->willFail('expired_card', 'Card expired');

        app(DunningService::class)->attempt($this->tenant, $this->invoice);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $entry = AuditLog::query()->where('action', 'status_changed')->latest('id')->first();

            $this->assertSame('past_due', $entry->after['status']);
            $this->assertStringContainsString('expired', $entry->after['reason']);
        });
    }

    #[Test]
    public function a_customer_who_pays_by_transfer_is_never_chased_by_card(): void
    {
        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => BillingProfile::query()->first()->update(['prefers_invoicing' => true]),
        );

        $attempt = app(DunningService::class)->attempt($this->tenant, $this->invoice);

        $this->assertSame(DunningAttempt::SKIPPED, $attempt->outcome);
        $this->assertSame([], $this->payments->charged, 'Nothing should have been charged.');
        $this->assertSame(Tenant::STATUS_ACTIVE, $this->tenant->refresh()->status);
    }

    #[Test]
    public function the_customer_is_told_the_whole_sequence_in_advance(): void
    {
        $this->payments->willFail('insufficient_funds', 'Insufficient funds');
        app(DunningService::class)->attempt($this->tenant, $this->invoice);

        $schedule = app(DunningService::class)->scheduleFor($this->tenant, $this->invoice);

        $this->assertSame(1, $schedule['attempts_made']);
        $this->assertSame(3, $schedule['attempts_remaining']);
        $this->assertNotNull($schedule['next_attempt']);

        $explanation = implode(' ', $schedule['what_happens_next']);
        $this->assertStringContainsString('read, download and export everything', $explanation);
    }
}
