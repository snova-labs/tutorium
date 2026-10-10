<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\SessionStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Report;
use App\Models\ReportDelivery;
use App\Models\TeacherNote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DemoAcademyBuilder;
use App\Services\TenantLifecycleService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** The demo academy: one build, checked for the states a demonstration needs to show. */
final class DemoAcademyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_a_term_in_progress_once_that_staff_can_sign_in_to(): void
    {
        $this->artisan('platform:demo-academy', ['--password' => 'a-demo-password'])->assertSuccessful();

        $tenant = $this->demo();
        $tenancy = app(TenantContext::class);

        $tenancy->runAs($tenant, function (): void {
            $this->assertGreaterThanOrEqual(80, Enrollment::query()->count());
            $this->assertTrue(Hash::check('a-demo-password', (string) User::query()->where('email', DemoAcademyBuilder::OWNER_EMAIL)->value('password')));

            // Registers are taken, with every kind of mark, and a few are still waiting.
            $this->assertGreaterThan(1000, AttendanceRecord::query()->count());
            $this->assertSame(4, AttendanceRecord::query()->distinct()->count('status_id'));
            $this->assertTrue(ClassSession::query()->where('status', SessionStatus::Scheduled)->where('ends_at_utc', '<', now())->exists());
            $this->assertTrue(ClassSession::query()->where('status', SessionStatus::Cancelled)->whereNotNull('cancel_reason')->exists());

            // Work is marked by the teacher, dated in the past, and some is still to mark.
            $this->assertGreaterThan(500, Grade::query()->count());
            $this->assertNull(Grade::query()->whereNull('graded_by')->first());
            $this->assertTrue(Grade::query()->where('graded_at', '<', now()->subWeek())->exists());

            $this->assertGreaterThan(100, TeacherNote::query()->count());
            $this->assertGreaterThan(30, Report::query()->count());
            $this->assertGreaterThan(10, ReportDelivery::query()->count());

            // A pause, a withdrawal and a transfer, each with its reason.
            $ended = Enrollment::query()->whereNotNull('status_reason')->pluck('status_reason');
            $this->assertCount(3, $ended);
            $this->assertTrue(Enrollment::query()->whereNotNull('transferred_to_enrollment_id')->exists());

            // Nothing that could reach a real person.
            $this->assertSame(0, Guardian::query()->whereNotNull('email')->where('email', 'not like', '%@example.com')->count());
            $this->assertSame(0, User::query()->where('email', 'not like', '%.example')->count());
        });

        // Running it again (one build only, to keep the suite quick) leaves this one alone.
        $this->artisan('platform:demo-academy')->expectsOutputToContain('already exists')->assertSuccessful();
        $this->assertSame($tenant->getKey(), $this->demo()->getKey());
    }

    #[Test]
    public function it_is_never_built_on_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('platform:demo-academy')->assertFailed();

        $this->assertFalse(app(DemoAcademyBuilder::class)->exists());
    }

    #[Test]
    public function only_the_demo_academy_can_be_removed_without_an_export(): void
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

    private function demo(): Tenant
    {
        return app(TenantContext::class)->withoutScoping(
            fn (): Tenant => Tenant::query()->where('slug', DemoAcademyBuilder::SLUG)->firstOrFail(),
        );
    }
}
