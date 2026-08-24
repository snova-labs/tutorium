<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SubscriptionChangeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Plan $starter;

    private Plan $growth;

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

        $this->starter = $this->plan('starter', 200, ['sso' => false, 'max_staff' => 5]);
        $this->growth = $this->plan('growth', 400, ['sso' => true, 'max_staff' => 25]);

        app(SubscriptionService::class)->subscribe($this->tenant, $this->starter);
    }

    #[Test]
    public function upgrading_grants_the_features_immediately(): void
    {
        $result = app(SubscriptionService::class)->changePlan($this->tenant, $this->growth);

        $this->assertSame('immediately', $result['effective']);
        $this->assertTrue(app(EntitlementService::class)->allows('sso', $this->tenant));
        // A few days of the better plan at the old rate, which removes an entire category of
        // billing dispute for very little money.
        $this->assertStringContainsString('rest of this period is at your old rate', $result['message']);
    }

    #[Test]
    public function downgrading_waits_until_the_end_of_the_paid_period(): void
    {
        app(SubscriptionService::class)->changePlan($this->tenant, $this->growth);
        app(EntitlementService::class)->forget();

        $result = app(SubscriptionService::class)->changePlan($this->tenant, $this->starter);

        $this->assertNotSame('immediately', $result['effective']);

        // Nobody loses access they have already paid for.
        app(EntitlementService::class)->forget();
        $this->assertTrue(app(EntitlementService::class)->allows('sso', $this->tenant));

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $subscription = Subscription::query()->latest('id')->first();
            $this->assertSame($this->starter->getKey(), $subscription->pending_plan_id);
        });
    }

    #[Test]
    public function a_downgrade_says_what_will_be_lost_before_it_happens(): void
    {
        app(SubscriptionService::class)->changePlan($this->tenant, $this->growth);
        app(EntitlementService::class)->forget();

        $result = app(SubscriptionService::class)->changePlan($this->tenant, $this->starter);

        // Told before it happens, not discovered afterwards.
        $this->assertStringContainsString('sso will no longer be available', $result['message']);
        $this->assertStringContainsString('max staff drops from 25 to 5', $result['message']);
    }

    #[Test]
    public function a_pending_downgrade_applies_when_its_date_arrives(): void
    {
        app(SubscriptionService::class)->changePlan($this->tenant, $this->growth);
        app(EntitlementService::class)->forget();
        app(SubscriptionService::class)->changePlan($this->tenant, $this->starter);

        $this->travelTo(now()->addMonth()->startOfMonth()->addDay());

        $this->assertSame(1, app(SubscriptionService::class)->applyPendingChanges());

        app(EntitlementService::class)->forget();
        $this->assertFalse(app(EntitlementService::class)->allows('sso', $this->tenant));
    }

    #[Test]
    public function cancelling_keeps_service_to_the_end_of_the_paid_period(): void
    {
        $subscription = app(SubscriptionService::class)->cancel($this->tenant);

        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->entitlesService());
    }

    /** @param array<string, mixed> $features */
    private function plan(string $code, int $unit, array $features): Plan
    {
        $plan = Plan::query()->create([
            'code' => $code, 'name' => ucfirst($code), 'currency' => 'EUR',
            'unit_price_minor' => $unit, 'minimum_charge_minor' => 0,
        ]);

        foreach ($features as $key => $value) {
            PlanFeature::query()->create([
                'plan_id' => $plan->getKey(),
                'feature_key' => $key,
                'value' => ['value' => $value],
                'enforcement' => PlanFeature::SOFT,
            ]);
        }

        return $plan;
    }
}
