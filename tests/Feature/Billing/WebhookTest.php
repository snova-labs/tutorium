<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use App\Services\WebhookProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Invoice $invoice;

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
            'status' => Tenant::STATUS_PAST_DUE,
        ])['tenant'];

        $plan = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 0,
        ]);

        app(TenantContext::class)->runAs($this->tenant, function () use ($plan): void {
            Subscription::query()->create([
                'plan_id' => $plan->getKey(),
                'status' => Subscription::PAST_DUE,
                'current_period_start' => now()->startOfMonth()->toDateString(),
                'current_period_end' => now()->endOfMonth()->toDateString(),
            ]);

            $this->invoice = Invoice::query()->create([
                'number' => 'INV-00042',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'quantity' => 50, 'currency' => 'EUR', 'unit_price_minor' => 200,
                'subtotal_minor' => 10_000, 'total_minor' => 10_000,
                'status' => Invoice::ISSUED, 'issued_at' => now(),
            ]);
        });
    }

    #[Test]
    public function a_successful_payment_settles_the_invoice_and_restores_the_account(): void
    {
        $event = app(WebhookProcessor::class)->record('stripe', $this->successEvent('evt_1'));
        app(WebhookProcessor::class)->process($event);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(Invoice::PAID, Invoice::query()->first()->status);
            $this->assertSame(Subscription::ACTIVE, Subscription::query()->first()->status);
        });

        $this->assertSame(Tenant::STATUS_ACTIVE, $this->tenant->refresh()->status);
    }

    #[Test]
    public function the_same_event_delivered_twice_is_handled_once(): void
    {
        $payload = $this->successEvent('evt_1');

        $first = app(WebhookProcessor::class)->record('stripe', $payload);
        app(WebhookProcessor::class)->process($first);

        // Providers retry for days. Handling this twice would settle a paid invoice again.
        $second = app(WebhookProcessor::class)->record('stripe', $payload);
        app(WebhookProcessor::class)->process($second);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PaymentEvent::query()->count());
    }

    #[Test]
    public function an_already_processed_event_does_nothing_on_a_second_pass(): void
    {
        $event = app(WebhookProcessor::class)->record('stripe', $this->successEvent('evt_1'));
        app(WebhookProcessor::class)->process($event);

        $paidAt = app(TenantContext::class)->runAs(
            $this->tenant, fn () => Invoice::query()->first()->paid_at,
        );

        $this->travel(1)->hour();
        app(WebhookProcessor::class)->process($event->refresh());

        app(TenantContext::class)->runAs($this->tenant, function () use ($paidAt): void {
            $this->assertEquals($paidAt, Invoice::query()->first()->paid_at);
        });
    }

    #[Test]
    public function an_unrecognised_event_is_stored_and_marked_handled(): void
    {
        $event = app(WebhookProcessor::class)->record('stripe', [
            'id' => 'evt_unknown', 'type' => 'charge.dispute.created', 'data' => ['object' => []],
        ]);

        app(WebhookProcessor::class)->process($event);

        // Stored for later inspection, not retried forever.
        $this->assertTrue($event->refresh()->isProcessed());
        $this->assertNull($event->error);
    }

    #[Test]
    public function an_unverifiable_webhook_is_rejected_at_the_door(): void
    {
        $this->postJson('/webhooks/payments', ['id' => 'evt_forged', 'type' => 'payment_intent.succeeded'])
            ->assertStatus(400);

        $this->assertSame(0, PaymentEvent::query()->count());
    }

    /** @return array<string, mixed> */
    private function successEvent(string $id): array
    {
        return [
            'id' => $id,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_test_123',
                'metadata' => ['invoice_number' => 'INV-00042'],
            ]],
        ];
    }
}
