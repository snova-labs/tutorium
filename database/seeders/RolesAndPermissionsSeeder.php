<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the permission catalogue and creates the example roles for one tenant.
 *
 * Safe to re-run: permissions are matched by name, and roles keep any customisation the tenant
 * has made to them — this seeder grants what is missing rather than resetting what is there.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= $this->tenancy->get();

        if ($tenant === null) {
            $this->command?->warn('No tenant bound — skipping roles and permissions.');

            return;
        }

        $this->tenancy->runAs($tenant, function () use ($tenant): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $names = $this->permissionNames();

            foreach ($names as $name) {
                Permission::findOrCreate($name, 'web');
            }

            // The owner role holds no stored permissions on purpose: it passes everything through
            // a Gate rule, so a permission added in a later release cannot lock an owner out.
            Role::findOrCreate(config('permissions.owner_role', 'Owner'), 'web');

            foreach (config('permissions.roles', []) as $roleName => $permissions) {
                $role = Role::findOrCreate($roleName, 'web');
                $role->givePermissionTo(array_intersect($permissions, $names));
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->command?->info(sprintf(
                'Synced %d permissions and %d roles for %s.',
                count($names),
                count(config('permissions.roles', [])) + 1,
                $tenant->name,
            ));
        });
    }

    /** @return array<int, string> */
    private function permissionNames(): array
    {
        $names = [];

        foreach (config('permissions.catalogue', []) as $group) {
            foreach (array_keys($group) as $permission) {
                $names[] = $permission;
            }
        }

        return $names;
    }
}
