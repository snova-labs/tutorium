<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command the stack runs on every deploy.
 *
 * Which is the whole risk: it must do its work the first time, and nothing — no second academy, no
 * failed deploy — every time after. The tests below are those two sentences.
 */
final class FirstRunCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_academy_its_owner_and_a_place_to_teach(): void
    {
        $this->configure();

        $this->artisan('platform:first-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Created Sunrise Academy.')
            ->expectsOutputToContain('Temporary password:');

        $tenant = $this->only();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame('owner@example.com', $tenant->contact_email);

        app(TenantContext::class)->runAs($tenant, function (): void {
            $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
            $this->assertTrue($owner->hasRole(config('permissions.owner_role', 'Owner')));
            $this->assertSame('Asia/Kathmandu', $owner->timezone);
            $this->assertSame('Asia/Kathmandu', Branch::query()->sole()->timezone);
            $this->assertTrue(Brand::query()->sole()->is_default);
        });
    }

    #[Test]
    public function a_second_deploy_changes_nothing(): void
    {
        $this->configure();
        $this->artisan('platform:first-run')->assertSuccessful();
        $users = $this->only()->users()->count();

        $this->artisan('platform:first-run')
            ->assertSuccessful()
            ->expectsOutputToContain('already exists');

        $this->assertSame(1, $this->tenants()->count());
        $this->assertSame($users, $this->only()->users()->count());
    }

    #[Test]
    public function an_unconfigured_environment_is_left_alone(): void
    {
        $this->artisan('platform:first-run')
            ->assertSuccessful()
            ->expectsOutputToContain('FIRST_RUN_ACADEMY is not set');

        $this->assertSame(0, $this->tenants()->count());
    }

    #[Test]
    public function a_chosen_password_is_used_and_never_printed(): void
    {
        $this->configure(['password' => 'a-password-of-my-own']);

        $this->artisan('platform:first-run')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('a-password-of-my-own');

        $tenant = $this->only();

        app(TenantContext::class)->runAs($tenant, function (): void {
            $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
            $this->assertTrue(password_verify('a-password-of-my-own', (string) $owner->password));
        });
    }

    #[Test]
    public function an_academy_with_no_owner_is_refused(): void
    {
        $this->configure(['owner_email' => '']);

        $this->artisan('platform:first-run')
            ->assertFailed()
            ->expectsOutputToContain('FIRST_RUN_OWNER_EMAIL');

        $this->assertSame(0, $this->tenants()->count());
    }

    #[Test]
    public function a_mistyped_email_or_timezone_is_refused_by_name(): void
    {
        $this->configure(['owner_email' => 'not-an-address']);
        $this->artisan('platform:first-run')
            ->assertFailed()
            ->expectsOutputToContain('is not an email address');

        $this->configure(['timezone' => 'Kathmandu']);
        $this->artisan('platform:first-run')
            ->assertFailed()
            ->expectsOutputToContain('is not a timezone');

        $this->assertSame(0, $this->tenants()->count());
    }

    #[Test]
    public function no_fail_reports_the_same_problem_without_failing_the_deploy(): void
    {
        // The stack passes --no-fail: a variable somebody mistyped should not stop the app, the
        // queue and the scheduler from starting.
        $this->configure(['timezone' => 'Kathmandu']);

        $this->artisan('platform:first-run', ['--no-fail' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('is not a timezone');

        $this->assertSame(0, $this->tenants()->count());
    }

    #[Test]
    public function an_unknown_preset_is_named_rather_than_guessed(): void
    {
        $this->configure(['preset' => 'astrology-school']);

        $this->artisan('platform:first-run')
            ->assertFailed()
            ->expectsOutputToContain('astrology-school');

        $this->assertSame(0, $this->tenants()->count());
    }

    /** @param array<string, string> $overrides */
    private function configure(array $overrides = []): void
    {
        config(['platform.first_run' => [
            'academy' => 'Sunrise Academy',
            'owner_name' => 'Sunrise Owner',
            'owner_email' => 'owner@example.com',
            'password' => '',
            'timezone' => 'Asia/Kathmandu',
            'preset' => 'blank',
            ...$overrides,
        ]]);
    }

    /** @return Builder<Tenant> */
    private function tenants(): Builder
    {
        return Tenant::query();
    }

    private function only(): Tenant
    {
        return $this->tenants()->sole();
    }
}
