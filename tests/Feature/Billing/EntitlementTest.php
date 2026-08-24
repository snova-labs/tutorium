<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Operator;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Services\EntitlementService;
use App\Services\TenantProvisioner;
use App\Support\Billing\Entitlement;
use App\Support\Billing\EntitlementSource;
use App\Support\Billing\LicenceEntitlements;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

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

        $this->growth = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 4000,
        ]);

        PlanFeature::query()->create([
            'plan_id' => $this->growth->getKey(), 'feature_key' => 'max_brands',
            'value' => ['value' => 5], 'enforcement' => PlanFeature::HARD,
        ]);
        PlanFeature::query()->create([
            'plan_id' => $this->growth->getKey(), 'feature_key' => 'max_staff',
            'value' => ['value' => 25], 'enforcement' => PlanFeature::SOFT,
        ]);
        PlanFeature::query()->create([
            'plan_id' => $this->growth->getKey(), 'feature_key' => 'sso',
            'value' => ['value' => false], 'enforcement' => PlanFeature::SOFT,
        ]);

        app(TenantContext::class)->runAs($this->tenant, fn () => Subscription::query()->create([
            'plan_id' => $this->growth->getKey(),
            'status' => Subscription::ACTIVE,
            'current_period_start' => now()->startOfMonth()->toDateString(),
            'current_period_end' => now()->endOfMonth()->toDateString(),
        ]));
    }

    #[Test]
    public function a_hard_limit_blocks_the_action_that_would_exceed_it(): void
    {
        $service = app(EntitlementService::class);

        // Four of five used: fine.
        $service->guardCreation('max_brands', 4, $this->tenant);

        try {
            $service->guardCreation('max_brands', 5, $this->tenant);
            $this->fail('A hard limit should block creation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Your plan covers 5', $e->getMessage());
        }
    }

    #[Test]
    public function a_soft_limit_warns_and_never_blocks(): void
    {
        $service = app(EntitlementService::class);

        // Over the staff limit, and nothing is prevented — because blocking here would stop an
        // academy adding the teacher who is standing in front of a class.
        $service->guardCreation('max_staff', 30, $this->tenant);

        $breaches = $service->softBreaches(['max_staff' => 30], $this->tenant);

        $this->assertCount(1, $breaches);
        $this->assertSame(25, $breaches[0]['limit']);
        $this->assertSame(30, $breaches[0]['current']);
    }

    #[Test]
    public function a_negotiated_override_wins_and_carries_its_reason(): void
    {
        $operator = Operator::factory()->create();

        app(TenantContext::class)->runAs($this->tenant, fn () => TenantEntitlementOverride::query()->create([
            'feature_key' => 'sso',
            'value' => ['value' => true],
            'reason' => 'Agreed during renewal, February 2026',
            'granted_by_operator_id' => $operator->getKey(),
        ]));

        app(EntitlementService::class)->forget();
        $entitlement = app(EntitlementService::class)->get('sso', $this->tenant);

        $this->assertTrue($entitlement->asBool());
        $this->assertSame(Entitlement::SOURCE_OVERRIDE, $entitlement->source);
        // A deal nobody can explain later becomes a dispute.
        $this->assertStringContainsString('Agreed during renewal', $entitlement->reason);
    }

    #[Test]
    public function an_expired_override_stops_applying(): void
    {
        app(TenantContext::class)->runAs($this->tenant, fn () => TenantEntitlementOverride::query()->create([
            'feature_key' => 'sso',
            'value' => ['value' => true],
            'reason' => 'Trial of single sign-on',
            'expires_at' => CarbonImmutable::now()->subDay(),
        ]));

        app(EntitlementService::class)->forget();

        $this->assertFalse(app(EntitlementService::class)->allows('sso', $this->tenant));
    }

    #[Test]
    public function an_unknown_feature_falls_back_to_a_shipped_default(): void
    {
        $entitlement = app(EntitlementService::class)->get('api_access', $this->tenant);

        $this->assertFalse($entitlement->asBool());
        $this->assertSame(Entitlement::SOURCE_DEFAULT, $entitlement->source);
    }

    #[Test]
    public function a_licence_answers_the_same_questions_as_a_subscription(): void
    {
        // The self-hosted path, exercised now so it cannot drift later.
        $this->app->instance(EntitlementSource::class, new LicenceEntitlements([
            'features' => ['max_learners' => 500, 'sso' => true, 'api_access' => true],
        ]));

        $service = $this->app->make(EntitlementService::class);

        $this->assertSame('licence', $service->sourceName());
        $this->assertTrue($service->allows('sso', $this->tenant));
        $this->assertSame(500, $service->limit('max_learners', $this->tenant));

        // Nothing in the product asked which source answered.
        try {
            $service->guardCreation('max_learners', 500, $this->tenant);
            $this->fail('A licence cap should block new enrollments.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Your plan covers 500', $e->getMessage());
        }
    }

    #[Test]
    public function an_unlimited_limit_never_blocks(): void
    {
        $service = app(EntitlementService::class);

        // Null means unlimited, which is a different thing from zero.
        $this->assertTrue($service->get('max_branches', $this->tenant)->isUnlimited());
        $service->guardCreation('max_branches', 10_000, $this->tenant);

        $this->addToAssertionCount(1);
    }
}
