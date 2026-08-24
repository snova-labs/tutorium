<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\BillingProfile;
use App\Models\IdSequence;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\TenantProvisioner;
use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentProvider::class, new FakePaymentProvider);

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
        ])['tenant'];

        $plan = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 0,
        ]);

        app(TenantContext::class)->runAs($this->tenant, function () use ($plan): void {
            IdSequence::query()->firstOrCreate(
                ['entity' => 'invoice', 'scope_type' => 'tenant', 'scope_id' => null],
                ['prefix' => 'INV', 'separator' => '-', 'pad_width' => 5, 'next_number' => 1, 'is_active' => true],
            );

            Subscription::query()->create([
                'plan_id' => $plan->getKey(), 'status' => Subscription::PAST_DUE,
                'current_period_start' => now()->startOfMonth()->toDateString(),
                'current_period_end' => now()->endOfMonth()->toDateString(),
            ]);

            BillingProfile::query()->create(['provider' => 'fake', 'customer_ref' => 'fake_cus_1']);

            $this->invoice = Invoice::query()->create([
                'number' => 'INV-00001',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'quantity' => 100, 'currency' => 'EUR', 'unit_price_minor' => 200,
                'subtotal_minor' => 20_000, 'total_minor' => 20_000,
                'status' => Invoice::ISSUED, 'issued_at' => now(),
            ]);
        });
    }

    #[Test]
    public function an_unsigned_request_is_refused_without_explanation(): void
    {
        $this->postJson('/webhooks/payments', $this->paidEvent(), ['Stripe-Signature' => 'nonsense'])
            ->assertUnauthorized();

        // Nothing is recorded, because nothing was authenticated.
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    #[Test]
    public function a_verified_payment_event_settles_the_invoice(): void
    {
        $this->send($this->paidEvent())->assertOk();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(Invoice::PAID, Invoice::query()->first()->status);
            $this->assertSame(Subscription::ACTIVE, Subscription::query()->first()->status);
        });
    }

    #[Test]
    public function the_same_event_twice_is_applied_once(): void
    {
        $event = $this->paidEvent();

        $this->send($event)->assertOk();
        $this->send($event)->assertOk()->assertJsonPath('message', 'Already processed.');

        // Providers retry, and a payment applied twice is worse than one applied late.
        $this->assertSame(1, WebhookEvent::query()->count());
    }

    #[Test]
    public function a_card_detail_event_stores_only_what_a_customer_needs_to_recognise_it(): void
    {
        $this->send([
            'id' => 'evt_method',
            'type' => 'payment_method.attached',
            'data' => ['object' => [
                'customer' => 'fake_cus_1',
                'card' => ['brand' => 'Visa', 'last4' => '4242', 'exp_month' => 9, 'exp_year' => 2029],
            ]],
        ])->assertOk();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $profile = BillingProfile::query()->first();

            $this->assertSame('Visa •••• 4242, expires 09/2029', $profile->methodSummary());
            // Nothing here could be used to charge anything.
            $this->assertArrayNotHasKey('number', $profile->getAttributes());
        });
    }

    #[Test]
    public function an_event_for_an_unknown_invoice_is_acknowledged_and_ignored(): void
    {
        $event = $this->paidEvent();
        $event['id'] = 'evt_unknown';
        $event['data']['object']['metadata']['invoice_number'] = 'INV-99999';

        $this->send($event)->assertOk();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(Invoice::ISSUED, Invoice::query()->first()->status);
        });
    }

    /** @return array<string, mixed> */
    private function paidEvent(): array
    {
        return [
            'id' => 'evt_paid_1',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_1',
                'metadata' => [
                    'invoice_number' => 'INV-00001',
                    'tenant_id' => (string) $this->tenant->getKey(),
                ],
            ]],
        ];
    }

    /** @param array<string, mixed> $event */
    private function send(array $event): TestResponse
    {
        return $this->call(
            'POST',
            '/webhooks/payments',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => 'valid-signature', 'CONTENT_TYPE' => 'application/json'],
            json_encode($event),
        );
    }
}
