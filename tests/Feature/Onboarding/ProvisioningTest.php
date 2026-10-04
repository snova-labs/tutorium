<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OnboardingChecklist;
use App\Services\SampleDataService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function provisioning_produces_an_account_that_works_immediately(): void
    {
        $result = $this->provision();

        $this->assertSame('trial', $result['tenant']->status);
        $this->assertNotNull($result['tenant']->trial_ends_at);

        app(TenantContext::class)->runAs($result['tenant'], function () use ($result): void {
            $this->assertTrue($result['owner']->fresh()->isOwner());
            $this->assertTrue($result['owner']->fresh()->can('settings.manage'));
            $this->assertSame('Asia/Kathmandu', Branch::query()->first()->timezone);
            $this->assertTrue(Brand::query()->first()->is_default);
        });
    }

    #[Test]
    public function two_academies_with_the_same_name_get_distinct_slugs(): void
    {
        $first = $this->provision(['name' => 'Bright Futures']);
        $second = $this->provision(['name' => 'Bright Futures', 'owner_email' => 'other@example.test']);

        $this->assertSame('bright-futures', $first['tenant']->slug);
        $this->assertSame('bright-futures-2', $second['tenant']->slug);
    }

    #[Test]
    public function an_unknown_preset_is_refused_before_anything_is_created(): void
    {
        $before = Tenant::query()->count();

        try {
            $this->provision(['preset_code' => 'not-a-real-preset']);
            $this->fail('An unknown preset should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no preset called', $e->getMessage());
        }

        $this->assertSame($before, Tenant::query()->count());
    }

    #[Test]
    public function the_checklist_is_derived_from_the_data_not_tracked(): void
    {
        $result = $this->provision();
        $checklist = app(OnboardingChecklist::class)->for($result['tenant']);

        $this->assertSame(6, $checklist['total']);
        $this->assertTrue(collect($checklist['steps'])->firstWhere('key', 'brand')['done']);
        $this->assertTrue(collect($checklist['steps'])->firstWhere('key', 'branch')['done']);
        // Nothing academic exists yet, so those steps are honestly still open.
        $this->assertFalse(collect($checklist['steps'])->firstWhere('key', 'course')['done']);
        $this->assertFalse($checklist['can_record_attendance']);
    }

    #[Test]
    public function the_checklist_speaks_the_tenants_own_vocabulary(): void
    {
        $result = $this->provision(['preset_code' => 'corporate-training']);
        $checklist = app(OnboardingChecklist::class)->for($result['tenant']);

        $titles = collect($checklist['steps'])->pluck('title')->implode(' | ');

        $this->assertStringContainsString('participants', $titles);
        $this->assertStringContainsString('cohort', $titles);
    }

    #[Test]
    public function sample_data_makes_the_product_demonstrable_and_then_disappears(): void
    {
        $result = $this->provision();
        $tenant = $result['tenant'];

        $set = app(SampleDataService::class)->load($tenant);

        $this->assertGreaterThan(20, count($set->created));
        $this->assertTrue(app(SampleDataService::class)->isLoaded($tenant));

        app(TenantContext::class)->runAs($tenant, function (): void {
            $this->assertTrue(app(OnboardingChecklist::class)
                ->for(app(TenantContext::class)->require())['can_record_attendance']);
        });

        $removed = app(SampleDataService::class)->remove($tenant);

        $this->assertSame(count($set->created), $removed);
        $this->assertFalse(app(SampleDataService::class)->isLoaded($tenant));
    }

    #[Test]
    public function removal_takes_only_what_the_load_created(): void
    {
        $result = $this->provision();
        $tenant = $result['tenant'];

        app(SampleDataService::class)->load($tenant);

        // A real learner whose name happens to look like sample data.
        $realId = app(TenantContext::class)->runAs($tenant, function (): int {
            return Learner::query()->create([
                'number' => 'REAL-001',
                'legal_name' => 'Sample Learner 99',
                'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
            ])->getKey();
        });

        app(SampleDataService::class)->remove($tenant);

        app(TenantContext::class)->runAs($tenant, function () use ($realId): void {
            // Removal works from the manifest, not from a name pattern — which is why this
            // learner survives.
            $this->assertNotNull(Learner::query()->find($realId));
            $this->assertSame(1, Learner::query()->count());
        });
    }

    #[Test]
    public function loading_sample_data_twice_is_refused(): void
    {
        $tenant = $this->provision()['tenant'];

        app(SampleDataService::class)->load($tenant);

        $this->expectException(ValidationException::class);
        app(SampleDataService::class)->load($tenant);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{tenant: Tenant, owner: User, brand: Brand, branch: Branch, preset: array<string, int>}
     */
    private function provision(array $overrides = []): array
    {
        return app(TenantProvisioner::class)->provision(array_merge([
            'name' => 'Sample Academy '.uniqid(),
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner-'.uniqid().'@example.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'week_start' => 'sunday',
            'weekend_days' => ['saturday'],
            'preset_code' => 'kids-tutoring-south-asia',
        ], $overrides));
    }
}
