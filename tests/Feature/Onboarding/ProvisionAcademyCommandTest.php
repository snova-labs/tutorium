<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProvisionAcademyCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_an_active_academy_whose_owner_can_use_the_password_shown(): void
    {
        $this->artisan('platform:provision-academy', [
            'name' => 'Sample Academy',
            'owner_email' => 'owner@sample.test',
            'owner_name' => 'Sample Owner',
            '--timezone' => 'Asia/Kathmandu',
        ])->assertSuccessful()->expectsOutputToContain('Temporary password:');

        $tenant = app(TenantContext::class)->withoutScoping(fn () => Tenant::query()->where('name', 'Sample Academy')->firstOrFail());
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);

        app(TenantContext::class)->runAs($tenant, function (): void {
            // Roles belong to an academy, so the check runs with that academy bound.
            $owner = User::query()->where('email', 'owner@sample.test')->firstOrFail();
            $this->assertTrue($owner->isOwner());
            $this->assertSame('Asia/Kathmandu', $owner->timezone);
        });
    }

    #[Test]
    public function the_password_printed_is_the_one_stored(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->artisan('platform:provision-academy', [
            'name' => 'Second Academy', 'owner_email' => 'second@sample.test', 'owner_name' => 'Second Owner',
        ]);

        preg_match('/Temporary password: (\S+)/', Artisan::output(), $match);
        $tenant = app(TenantContext::class)->withoutScoping(fn () => Tenant::query()->where('name', 'Second Academy')->firstOrFail());
        $owner = app(TenantContext::class)->runAs($tenant, fn () => User::query()->firstOrFail());

        $this->assertTrue(Hash::check($match[1] ?? '', $owner->password));
    }

    #[Test]
    public function it_refuses_a_bad_email_or_timezone_and_creates_nothing(): void
    {
        $this->artisan('platform:provision-academy', [
            'name' => 'Nope', 'owner_email' => 'not-an-email', 'owner_name' => 'X',
        ])->assertExitCode(2);

        $this->artisan('platform:provision-academy', [
            'name' => 'Nope', 'owner_email' => 'x@sample.test', 'owner_name' => 'X', '--timezone' => 'Mars/Olympus',
        ])->assertExitCode(2);

        $this->assertSame(0, app(TenantContext::class)->withoutScoping(fn () => Tenant::query()->count()));
    }
}
