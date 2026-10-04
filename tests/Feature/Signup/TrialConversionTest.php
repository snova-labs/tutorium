<?php

declare(strict_types=1);

namespace Tests\Feature\Signup;

use App\Models\AuditLog;
use App\Models\BillingProfile;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Services\TrialService;
use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\ManualPaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * From trial to paying in one step, with everything entered during the trial carried over
 * untouched. Nobody re-types a roster.
 */
final class TrialConversionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->app->instance(PaymentProvider::class, new FakePaymentProvider);

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
            'trial_days' => 14,
        ])['tenant'];

        $this->plan = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 0, 'is_public' => true,
        ]);

        // Something entered during the trial, to prove it survives.
        app(TenantContext::class)->runAs($this->tenant, fn () => Branch::query()->create([
            'brand_id' => Brand::query()->firstOrFail()->getKey(),
            'name' => 'Second location',
            'code' => 'TWO',
            'timezone' => 'Asia/Kathmandu',
        ]));

        Sanctum::actingAs(app(TenantContext::class)->runAs($this->tenant, fn () => User::query()->firstOrFail()));
    }

    #[Test]
    public function choosing_a_plan_and_invoicing_converts_at_once_with_everything_kept(): void
    {
        $branches = $this->branchCount();

        $this->postJson('/api/v1/billing/convert', [
            'plan_code' => 'growth',
            'method' => 'invoice',
            'legal_name' => 'Sample Academy Ltd',
            'billing_email' => 'accounts@sample.test',
        ])->assertOk()->assertJsonPath('data.converted', true);

        $tenant = $this->tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNull($tenant->trial_ends_at);
        $this->assertSame($branches, $this->branchCount(), 'Everything carries over untouched.');

        $subscription = $this->subscription();
        $this->assertNotNull($subscription);
        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertSame($this->plan->getKey(), $subscription->plan_id);

        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('action', 'status_changed')
            ->exists());
    }

    #[Test]
    public function a_lapsed_trial_converts_from_read_only_and_can_write_again(): void
    {
        $this->travel(15)->days();
        app(TrialService::class)->expireDue();
        $this->assertSame(Tenant::STATUS_SUSPENDED, $this->tenant->refresh()->status);

        $this->postJson('/api/v1/brands', ['name' => 'Nope', 'code' => 'NOPE'])->assertStatus(423);

        $this->postJson('/api/v1/billing/convert', [
            'plan_code' => 'growth', 'method' => 'invoice',
            'legal_name' => 'Sample Academy Ltd', 'billing_email' => 'accounts@sample.test',
        ])->assertOk()->assertJsonPath('data.converted', true);

        $this->assertSame(Tenant::STATUS_ACTIVE, $this->tenant->refresh()->status);
        $this->assertSame(2, $this->branchCount());
        $this->postJson('/api/v1/brands', ['name' => 'Second', 'code' => 'SECOND'])->assertCreated();
    }

    #[Test]
    public function choosing_card_sends_to_the_hosted_page_and_converts_when_the_card_arrives(): void
    {
        $this->postJson('/api/v1/billing/convert', ['plan_code' => 'growth', 'method' => 'card'])
            ->assertOk()
            ->assertJsonPath('data.converted', false)
            ->assertJsonPath('data.checkout_url', 'https://payments.test/checkout/'.$this->tenant->slug);

        // Nothing changes until there is a way to pay.
        $this->assertSame(Tenant::STATUS_TRIAL, $this->tenant->refresh()->status);
        $this->assertSame(Subscription::TRIALING, $this->subscription()?->status);

        $customer = app(TenantContext::class)->runAs($this->tenant, fn () => BillingProfile::query()->firstOrFail()->customer_ref);

        $this->call('POST', '/webhooks/payments', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'valid-signature', 'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'id' => 'evt_card',
            'type' => 'payment_method.attached',
            'data' => ['object' => [
                'customer' => $customer,
                'card' => ['brand' => 'Visa', 'last4' => '4242', 'exp_month' => 9, 'exp_year' => (int) now()->addYears(3)->format('Y')],
            ]],
        ], JSON_THROW_ON_ERROR))->assertOk();

        $this->assertSame(Tenant::STATUS_ACTIVE, $this->tenant->refresh()->status);
        $this->assertSame(Subscription::ACTIVE, $this->subscription()?->status);
        $this->assertSame(2, $this->branchCount());
    }

    #[Test]
    public function a_card_already_on_file_converts_at_once(): void
    {
        app(TenantContext::class)->runAs($this->tenant, fn () => BillingProfile::query()->create([
            'provider' => 'fake', 'customer_ref' => 'fake_cus_1',
            'method_brand' => 'Visa', 'method_last_four' => '4242',
            'method_exp_month' => 9, 'method_exp_year' => (int) now()->addYears(3)->format('Y'),
        ]));

        $this->postJson('/api/v1/billing/convert', ['plan_code' => 'growth', 'method' => 'card'])
            ->assertOk()->assertJsonPath('data.converted', true)->assertJsonPath('data.checkout_url', null);

        $this->assertSame(Tenant::STATUS_ACTIVE, $this->tenant->refresh()->status);
    }

    #[Test]
    public function invoicing_without_an_addressee_changes_nothing(): void
    {
        $this->postJson('/api/v1/billing/convert', ['plan_code' => 'growth', 'method' => 'invoice'])
            ->assertStatus(422)->assertJsonValidationErrors(['legal_name', 'billing_email']);

        $this->assertSame(Tenant::STATUS_TRIAL, $this->tenant->refresh()->status);
        $this->assertNull($this->subscription());
    }

    #[Test]
    public function only_offered_plans_and_only_trials(): void
    {
        Plan::query()->create([
            'code' => 'secret', 'name' => 'Secret', 'currency' => 'EUR',
            'unit_price_minor' => 1, 'minimum_charge_minor' => 0, 'is_public' => false,
        ]);

        $this->postJson('/api/v1/billing/convert', ['plan_code' => 'secret', 'method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_code');

        app(TrialService::class)->convert($this->tenant);
        // A fresh request loads the tenant afresh; the test's acting user would otherwise keep it cached.
        Sanctum::actingAs(app(TenantContext::class)->runAs($this->tenant, fn () => User::query()->firstOrFail()));

        $this->postJson('/api/v1/billing/convert', ['plan_code' => 'growth', 'method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('account');
    }

    #[Test]
    public function an_invoice_only_setup_cannot_choose_card(): void
    {
        $this->app->instance(PaymentProvider::class, new ManualPaymentProvider);

        $this->postJson('/api/v1/billing/convert', ['plan_code' => 'growth', 'method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('method');

        $this->assertNull($this->subscription());
    }

    private function branchCount(): int
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => Branch::query()->count());
    }

    private function subscription(): ?Subscription
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => Subscription::query()->latest('id')->first());
    }
}
