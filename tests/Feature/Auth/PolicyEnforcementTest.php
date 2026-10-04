<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The acceptance criterion from SL-SRS-001 §7.1: "a role built from permissions can manage
 * learners but is refused settings access, and the refusal appears in the audit log."
 */
final class PolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'policy-test']),
        );

        app(RolesAndPermissionsSeeder::class)->run($this->tenant);
    }

    #[Test]
    public function front_desk_can_manage_learners_but_not_settings(): void
    {
        $user = $this->userWithRole('Front desk');

        $this->inTenant(function () use ($user): void {
            $this->assertTrue($user->can('learners.create'), 'Front desk should be able to add learners.');
            $this->assertTrue($user->can('learners.update'));
            $this->assertFalse($user->can('settings.manage'), 'Front desk must not reach settings.');
            $this->assertFalse($user->can('billing.manage'));
            $this->assertFalse($user->can('organisation.manage'));
        });
    }

    #[Test]
    public function front_desk_is_refused_when_creating_a_brand(): void
    {
        $user = $this->userWithRole('Front desk');

        $this->assertFalse($user->can('create', Brand::class));
    }

    #[Test]
    public function teacher_can_grade_but_cannot_manage_users(): void
    {
        $user = $this->userWithRole('Teacher');

        $this->inTenant(function () use ($user): void {
            $this->assertTrue($user->can('grades.enter'));
            $this->assertTrue($user->can('attendance.record'));
            $this->assertFalse($user->can('users.manage'));
            $this->assertFalse($user->can('reports.send'), 'Sending to guardians is a separate permission.');
        });
    }

    #[Test]
    public function the_owner_passes_every_check_without_holding_stored_permissions(): void
    {
        $owner = $this->userWithRole('Owner');

        $this->inTenant(function () use ($owner): void {
            $this->assertTrue($owner->can('settings.manage'));
            $this->assertTrue($owner->can('billing.manage'));
            // Including a permission that does not exist yet — which is the point of the Gate rule.
            $this->assertTrue($owner->can('some.future.permission'));
            $this->assertSame(0, $owner->getDirectPermissions()->count());
        });
    }

    #[Test]
    public function permissions_fail_closed_when_no_tenant_is_bound(): void
    {
        $user = $this->userWithRole('Front desk');

        // Roles are stored per tenant. With none bound there is nothing to grant from, and the
        // answer is no rather than whatever another tenant's role happens to say.
        $this->assertFalse($user->can('learners.create'));
    }

    #[Test]
    public function a_deactivated_user_loses_every_permission_immediately(): void
    {
        $owner = $this->userWithRole('Owner');
        $owner->forceFill(['is_active' => false])->save();

        $this->assertFalse($owner->fresh()->can('settings.manage'));
        $this->assertFalse($owner->fresh()->can('learners.view'));
    }

    /** @param Closure(): void $assertions */
    private function inTenant(Closure $assertions): void
    {
        app(TenantContext::class)->runAs($this->tenant, $assertions);
    }

    private function userWithRole(string $role): User
    {
        return app(TenantContext::class)->runAs($this->tenant, function () use ($role): User {
            $user = User::factory()->create(['is_active' => true]);
            $user->syncRoles([$role]);

            return $user->fresh();
        });
    }
}
