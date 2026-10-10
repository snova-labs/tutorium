<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DemoSuite;
use App\Services\TenantLifecycleService;
use App\Services\TenantProvisioner;
use App\Support\Demo\DemoProfiles;
use App\Support\Presets\PresetRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** The demo academies and the page that loads and removes them. */
final class DemoTest extends TestCase
{
    use RefreshDatabase;

    /** The quickest academy to build; the others are checked as data below. */
    private const ONE = 'demo-summit-learning';

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.password' => 'open-sesame', 'demo.only' => [self::ONE]]);
    }

    #[Test]
    public function the_page_loads_a_term_in_progress_and_removes_it_again(): void
    {
        $this->getJson('/api/v1/demo')->assertOk()->assertJsonPath('data.state', 'none');

        // The queue runs synchronously in tests, so the build finishes inside this request.
        $this->postJson('/api/v1/demo', ['password' => 'open-sesame'])->assertStatus(202);

        $status = $this->getJson('/api/v1/demo')->assertOk()->assertJsonPath('data.state', 'ready');
        $this->assertSame('Summit Corporate Learning', $status->json('data.academies.0.academy'));
        $this->assertSame('megan@summitlearning.example', $status->json('data.academies.0.accounts.0.email'));

        $tenant = $this->tenant(self::ONE);

        app(TenantContext::class)->runAs($tenant, function (): void {
            $this->assertTrue(Hash::check('open-sesame', (string) User::query()->where('email', 'megan@summitlearning.example')->value('password')));
            $this->assertGreaterThan(300, AttendanceRecord::query()->count());
            $this->assertGreaterThan(200, Grade::query()->count());
            $this->assertNull(Grade::query()->whereNull('graded_by')->first());
            $this->assertGreaterThan(10, Report::query()->count());
            $this->assertCount(3, Enrollment::query()->whereNotNull('status_reason')->pluck('id'));
            // Every participant is sent by a company, whose contact receives the reports.
            $this->assertSame(0, Guardian::query()->where('email', 'not like', '%.example')->count());
        });

        $this->deleteJson('/api/v1/demo', ['password' => 'open-sesame'])->assertStatus(202);
        $this->getJson('/api/v1/demo')->assertJsonPath('data.state', 'none');
        $this->assertSame([], app(DemoSuite::class)->tenants());
    }

    #[Test]
    public function the_wrong_password_changes_nothing(): void
    {
        $this->postJson('/api/v1/demo', ['password' => 'guess'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->deleteJson('/api/v1/demo', ['password' => 'guess'])->assertStatus(422);

        $this->assertSame([], app(DemoSuite::class)->tenants());
    }

    #[Test]
    public function the_page_does_not_exist_without_a_password_or_on_production(): void
    {
        config(['demo.password' => '']);
        $this->getJson('/api/v1/demo')->assertNotFound();
        $this->postJson('/api/v1/demo', ['password' => ''])->assertNotFound();

        config(['demo.password' => 'open-sesame']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/api/v1/demo')->assertNotFound();
        $this->postJson('/api/v1/demo', ['password' => 'open-sesame'])->assertNotFound();
        $this->artisan('platform:demo')->assertFailed();
    }

    #[Test]
    public function only_demo_academies_can_be_removed_without_an_export(): void
    {
        $customer = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];

        $this->expectException(RuntimeException::class);

        app(TenantLifecycleService::class)->removeDemo($customer);
    }

    /** Every academy hangs together: the references a build would trip over, checked without building. */
    #[Test]
    public function every_profile_refers_only_to_things_it_defines(): void
    {
        $presets = app(PresetRepository::class);

        foreach (DemoProfiles::all() as $p) {
            $this->assertTrue($presets->exists($p['preset']), $p['slug'].': unknown preset');
            $this->assertStringStartsWith('demo-', $p['slug']);
            $this->assertStringEndsWith('.example', $p['domain']);

            $courses = array_column($p['courses'], 0);
            $branches = array_column($p['branches'], 0);
            $staff = array_column($p['staff'], 0);
            $classes = array_column($p['classes'], 0);

            foreach ($p['classes'] as $class) {
                $this->assertContains($class[1], $courses, "{$p['slug']}: {$class[0]} course");
                $this->assertContains($class[2], $branches, "{$p['slug']}: {$class[0]} branch");
                $this->assertContains($class[6], $staff, "{$p['slug']}: {$class[0]} teacher");
                $this->assertLessThanOrEqual($class[5], $class[11], "{$p['slug']}: {$class[0]} over capacity");
            }

            foreach ([$p['events']['withdraw'][0], $p['events']['hold'][0], ...array_slice($p['events']['transfer'], 0, 2),
                ...$p['reports']['send'], ...$p['reports']['generate']] as $handle) {
                $this->assertContains($handle, $classes, "{$p['slug']}: unknown class {$handle}");
            }

            $this->assertContains('manager', $staff, "{$p['slug']}: needs a manager to cancel and report");
        }
    }

    private function tenant(string $slug): Tenant
    {
        return app(TenantContext::class)->withoutScoping(fn (): Tenant => Tenant::query()->where('slug', $slug)->firstOrFail());
    }
}
