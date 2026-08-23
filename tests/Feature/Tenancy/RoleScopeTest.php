<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Roles are per tenant. Without this, one academy renaming "Front desk" or granting it billing
 * access would change what that role means for every other academy on the platform.
 */
final class RoleScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_role_customised_by_one_tenant_does_not_affect_another(): void
    {
        [$a, $b] = $this->twoSeededTenants();
        $context = app(TenantContext::class);

        // Tenant A decides their front desk should also handle billing.
        $context->runAs($a, function (): void {
            Role::findByName('Front desk', 'web')->givePermissionTo('billing.manage');
        });

        $userA = $this->userWithRole($a, 'Front desk');
        $userB = $this->userWithRole($b, 'Front desk');

        $this->assertTrue($context->runAs($a, fn () => $userA->fresh()->can('billing.manage')));
        $this->assertFalse(
            $context->runAs($b, fn () => $userB->fresh()->can('billing.manage')),
            'One tenant granting a permission must not grant it everywhere.',
        );
    }

    #[Test]
    public function a_role_invented_by_one_tenant_is_invisible_to_another(): void
    {
        [$a, $b] = $this->twoSeededTenants();
        $context = app(TenantContext::class);

        $context->runAs($a, function (): void {
            Role::findOrCreate('Exams coordinator', 'web');
        });

        $context->runAs($b, function () use ($b): void {
            $this->assertSame(
                0,
                Role::query()
                    ->where('name', 'Exams coordinator')
                    ->where('team_id', $b->getKey())
                    ->count(),
                'A role invented by one tenant must not exist under another.',
            );
        });
    }

    /** @return array{0: Tenant, 1: Tenant} */
    private function twoSeededTenants(): array
    {
        $context = app(TenantContext::class);

        $a = $context->withoutScoping(fn () => Tenant::factory()->create(['slug' => 'roles-a']));
        $b = $context->withoutScoping(fn () => Tenant::factory()->create(['slug' => 'roles-b']));

        app(RolesAndPermissionsSeeder::class)->run($a);
        app(RolesAndPermissionsSeeder::class)->run($b);

        return [$a, $b];
    }

    private function userWithRole(Tenant $tenant, string $role): User
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($role): User {
            $user = User::factory()->create(['is_active' => true]);
            $user->syncRoles([$role]);

            return $user->fresh();
        });
    }
}
