<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\IdSequence;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageSnapshot;
use App\Services\InvoiceComposer;
use App\Services\MeteringService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An invoice a customer can check against their own roster without asking us anything.
 */
final class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
        ])['tenant'];

        // €2.00 per active learner, €40.00 minimum.
        $this->plan = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 4000,
        ]);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Subscription::query()->create([
                'plan_id' => $this->plan->getKey(),
                'status' => Subscription::ACTIVE,
                'current_period_start' => '2026-08-01',
                'current_period_end' => '2026-08-31',
            ]);

            IdSequence::query()->firstOrCreate(
                ['entity' => 'invoice', 'scope_type' => 'tenant', 'scope_id' => null],
                ['prefix' => 'INV', 'separator' => '-', 'pad_width' => 5, 'next_number' => 1, 'is_active' => true],
            );
        });
    }

    #[Test]
    public function the_quantity_is_traceable_to_the_day_it_came_from(): void
    {
        $this->measure('2026-08-01', 170);
        $peakDay = $this->measure('2026-08-14', 184);
        $this->measure('2026-08-31', 178);

        $invoice = $this->draft();

        $this->assertSame(184, $invoice->quantity);
        $this->assertSame('2026-08-14', $invoice->quantity_basis_date->toDateString());
        $this->assertSame($peakDay->getKey(), $invoice->quantity_snapshot_id);
        $this->assertStringContainsString('184 active learners on 2026-08-14', implode(' ', $invoice->notes));
    }

    #[Test]
    public function the_arithmetic_is_plain(): void
    {
        $this->measure('2026-08-14', 184);

        $invoice = $this->draft();

        // 184 × €2.00 = €368.00, comfortably above the €40.00 minimum.
        $this->assertSame(36_800, $invoice->subtotal_minor);
        $this->assertSame(0, $invoice->minimum_adjustment_minor);
        $this->assertSame(36_800, $invoice->total_minor);
        $this->assertSame('368.00 EUR', $invoice->total());
    }

    #[Test]
    public function a_minimum_charge_appears_as_its_own_line(): void
    {
        $this->measure('2026-08-14', 3);

        $invoice = $this->draft();

        // 3 × €2.00 = €6.00, so €34.00 is added to reach the €40.00 minimum. Stated separately,
        // so a customer with three learners can see why they were charged for more.
        $this->assertSame(600, $invoice->subtotal_minor);
        $this->assertSame(3_400, $invoice->minimum_adjustment_minor);
        $this->assertSame(4_000, $invoice->total_minor);
        $this->assertStringContainsString('minimum charge', implode(' ', $invoice->notes));
    }

    #[Test]
    public function a_correction_is_named_on_the_invoice(): void
    {
        $original = $this->measure('2026-08-19', 249);

        app(MeteringService::class)->correct(
            $this->tenant, $original, 247,
            'Duplicate enrollment created during an import',
            Operator::factory()->create(),
        );

        $invoice = $this->draft();

        $this->assertSame(247, $invoice->quantity);
        // Shown to the customer rather than kept in our own records.
        $this->assertStringContainsString(
            'Duplicate enrollment created during an import',
            implode(' ', $invoice->notes),
        );
    }

    #[Test]
    public function unmeasured_days_are_declared_rather_than_glossed_over(): void
    {
        $this->measure('2026-08-01', 100);
        $this->measure('2026-08-02', 110);
        $this->travelTo('2026-09-01 02:00:00');

        $invoice = $this->draft();

        $this->assertStringContainsString('were not measured', implode(' ', $invoice->notes));
    }

    #[Test]
    public function the_counting_rule_is_restated_on_every_invoice(): void
    {
        $this->measure('2026-08-14', 50);

        $notes = implode(' ', $this->draft()->notes);

        $this->assertStringContainsString('Keeping history is free', $notes);
    }

    #[Test]
    public function an_issued_invoice_cannot_be_edited(): void
    {
        $this->measure('2026-08-14', 50);

        $invoice = app(InvoiceComposer::class)->issue($this->draft());

        $this->assertFalse($invoice->isEditable());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('credit note');

        app(InvoiceComposer::class)->issue($invoice);
    }

    private function draft(): Invoice
    {
        return app(InvoiceComposer::class)->draft(
            $this->tenant,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );
    }

    private function measure(string $date, int $count): UsageSnapshot
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => UsageSnapshot::query()->create([
            'snapshot_date' => $date,
            'active_learners' => $count,
        ]));
    }
}
