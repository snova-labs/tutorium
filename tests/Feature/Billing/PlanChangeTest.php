<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Brand;
use App\Models\IdSequence;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageSnapshot;
use App\Services\InvoiceComposer;
use App\Services\SubscriptionService;
use App\Services\TenantProvisioner;
use App\Support\Payments\ManualPaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Moving between plans on a metered product.
 *
 * The arithmetic in the invoice test is written out so anyone can check it, because a customer who
 * changes plan mid-month and gets a bill they cannot follow will assume they were overcharged.
 */
final class PlanChangeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Plan $starter;

    private Plan $growth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentProvider::class, new ManualPaymentProvider);

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
            'status' => Tenant::STATUS_ACTIVE,
        ])['tenant'];

        // €1.00 per learner on Starter, €2.00 on Growth — chosen so the split is easy to verify.
        $this->starter = $this->plan('starter', 'Starter', 100, ['max_brands' => 1, 'max_staff' => 5]);
        $this->growth = $this->plan('growth', 'Growth', 200, ['max_brands' => 5, 'max_staff' => 25]);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            IdSequence::query()->firstOrCreate(
                ['entity' => 'invoice', 'scope_type' => 'tenant', 'scope_id' => null],
                ['prefix' => 'INV', 'separator' => '-', 'pad_width' => 5, 'next_number' => 1, 'is_active' => true],
            );
        });
    }

    #[Test]
    public function an_upgrade_applies_immediately(): void
    {
        app(SubscriptionService::class)->subscribe($this->tenant, $this->starter);

        $result = app(SubscriptionService::class)->changePlan($this->tenant, $this->growth);

        $this->assertSame(now()->toDateString(), $result['effective']);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // Someone paying more should get what they paid for now, not next month.
            $this->assertSame($this->growth->getKey(), Subscription::query()->first()->plan_id);
            $this->assertNotNull(PlanChange::query()->first()->applied_at);
        });
    }

    #[Test]
    public function a_downgrade_waits_for_the_end_of_the_paid_period(): void
    {
        $subscription = app(SubscriptionService::class)->subscribe($this->tenant, $this->growth);

        $result = app(SubscriptionService::class)->changePlan($this->tenant, $this->starter, true);

        $this->assertSame(
            $subscription->current_period_end->addDay()->toDateString(),
            $result['effective'],
        );

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // Nobody loses capability they have already paid for.
            $this->assertSame($this->growth->getKey(), Subscription::query()->first()->plan_id);
            $this->assertTrue(PlanChange::query()->first()->isPending());
        });
    }

    #[Test]
    public function a_downgrade_that_would_not_fit_says_exactly_what_would_not_fit(): void
    {
        app(SubscriptionService::class)->subscribe($this->tenant, $this->growth);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Brand::factory()->count(2)->create();
        });

        try {
            app(SubscriptionService::class)->changePlan($this->tenant, $this->starter);
            $this->fail('A downgrade that would not fit should be refused until acknowledged.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('you have 3 brands and it allows 1', $e->getMessage());
            // Told before the change, not discovered after it.
            $this->assertStringContainsString('Nothing will be deleted', $e->getMessage());
        }
    }

    #[Test]
    public function an_acknowledged_downgrade_proceeds_and_deletes_nothing(): void
    {
        app(SubscriptionService::class)->subscribe($this->tenant, $this->growth);

        app(TenantContext::class)->runAs($this->tenant, fn () => Brand::factory()->count(2)->create());

        app(SubscriptionService::class)->changePlan($this->tenant, $this->starter, acknowledgeLosses: true);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(3, Brand::query()->count(), 'Over the limit, and still there.');
        });
    }

    #[Test]
    public function a_scheduled_downgrade_applies_when_its_date_arrives(): void
    {
        $subscription = app(SubscriptionService::class)->subscribe($this->tenant, $this->growth);
        app(SubscriptionService::class)->changePlan($this->tenant, $this->starter, true);

        $this->travelTo($subscription->current_period_end->addDays(2)->toDateString().' 03:00:00');

        $this->assertSame(1, app(SubscriptionService::class)->applyScheduledChanges());

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame($this->starter->getKey(), Subscription::query()->first()->plan_id);
            $this->assertNotNull(PlanChange::query()->first()->applied_at);
        });
    }

    #[Test]
    public function a_mid_period_upgrade_splits_the_invoice_rather_than_prorating_a_fee(): void
    {
        $this->travelTo('2026-08-01 09:00:00');
        app(SubscriptionService::class)->subscribe($this->tenant, $this->starter);

        // Ten learners in the first half of the month.
        $this->measure('2026-08-05', 10);

        $this->travelTo('2026-08-15 09:00:00');
        app(SubscriptionService::class)->changePlan($this->tenant, $this->growth);

        // Twelve in the second half.
        $this->measure('2026-08-20', 12);

        $this->travelTo('2026-09-01 03:00:00');

        $invoice = app(InvoiceComposer::class)->draft(
            $this->tenant,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $lines = $invoice->lines()->orderBy('sort')->get();

        //   1–14 Aug on Starter:  peak 10 × €1.00 = €10.00
        //  15–31 Aug on Growth:   peak 12 × €2.00 = €24.00
        //                                  total  = €34.00
        //
        // Prorating a flat fee would have been meaningless here — there is no flat fee to prorate.
        $this->assertCount(2, $lines);
        $this->assertSame(10, $lines[0]->quantity);
        $this->assertSame(1_000, $lines[0]->amount_minor);
        $this->assertSame('2026-08-14', $lines[0]->period_end->toDateString());

        $this->assertSame(12, $lines[1]->quantity);
        $this->assertSame(2_400, $lines[1]->amount_minor);
        $this->assertSame('2026-08-15', $lines[1]->period_start->toDateString());

        $this->assertSame(3_400, $invoice->total_minor);
    }

    #[Test]
    public function a_minimum_charge_applies_once_across_a_split_period(): void
    {
        $growthWithMinimum = Plan::query()->create([
            'code' => 'growth-min', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 5_000,
        ]);

        $this->travelTo('2026-08-01 09:00:00');
        app(SubscriptionService::class)->subscribe($this->tenant, $this->starter);
        $this->measure('2026-08-05', 4);

        $this->travelTo('2026-08-15 09:00:00');
        app(SubscriptionService::class)->changePlan($this->tenant, $growthWithMinimum);
        $this->measure('2026-08-20', 5);

        $this->travelTo('2026-09-01 03:00:00');

        $invoice = app(InvoiceComposer::class)->draft(
            $this->tenant,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        // €4.00 + €10.00 = €14.00, lifted once to the €50.00 minimum — not twice.
        $this->assertSame(1_400, $invoice->subtotal_minor);
        $this->assertSame(3_600, $invoice->minimum_adjustment_minor);
        $this->assertSame(5_000, $invoice->total_minor);
    }

    #[Test]
    public function cancelling_runs_to_the_end_of_the_paid_period(): void
    {
        $subscription = app(SubscriptionService::class)->subscribe($this->tenant, $this->growth);

        $cancelled = app(SubscriptionService::class)->cancel($this->tenant, 'Closing the centre');

        $this->assertTrue($cancelled->cancel_at_period_end);
        // The month is already paid for, and an academy mid-term should not lose access because
        // someone clicked cancel.
        $this->assertTrue($cancelled->entitlesService());
        $this->assertSame($subscription->current_period_end->toDateString(), $cancelled->current_period_end->toDateString());

        app(SubscriptionService::class)->resume($this->tenant);
        $this->assertFalse(Subscription::query()->withoutGlobalScopes()->first()->cancel_at_period_end);
    }

    /** @param array<string, int> $limits */
    private function plan(string $code, string $name, int $unit, array $limits): Plan
    {
        $plan = Plan::query()->create([
            'code' => $code, 'name' => $name, 'currency' => 'EUR',
            'unit_price_minor' => $unit, 'minimum_charge_minor' => 0,
        ]);

        foreach ($limits as $key => $value) {
            PlanFeature::query()->create([
                'plan_id' => $plan->getKey(), 'feature_key' => $key,
                'value' => ['value' => $value], 'enforcement' => PlanFeature::HARD,
            ]);
        }

        return $plan;
    }

    private function measure(string $date, int $count): UsageSnapshot
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => UsageSnapshot::query()->create([
            'snapshot_date' => $date,
            'active_learners' => $count,
        ]));
    }
}
