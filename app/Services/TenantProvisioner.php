<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Presets\PresetApplier;
use App\Support\Presets\PresetRepository;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a signup form into a working account.
 *
 * One operation, one transaction. A half-provisioned tenant — permissions but no owner, or a brand
 * with no branch — is worse than a failed signup, because nobody knows it is broken until a
 * teacher tries to take a register.
 *
 * Used by the operator console today and by self-serve signup at P4; both go through here so the
 * two paths cannot drift apart.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly PresetApplier $presets,
        private readonly PresetRepository $catalogue,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{tenant: Tenant, owner: User, brand: Brand, branch: Branch, preset: array<string, int>}
     */
    public function provision(array $input): array
    {
        $presetCode = $input['preset_code'] ?? 'blank';

        if (! $this->catalogue->exists($presetCode)) {
            throw ValidationException::withMessages([
                'preset_code' => "There is no preset called [{$presetCode}].",
            ]);
        }

        return DB::transaction(function () use ($input, $presetCode): array {
            $tenant = $this->tenancy->withoutScoping(fn () => Tenant::query()->create([
                'name' => $input['name'],
                'slug' => $this->uniqueSlug($input['name']),
                'status' => $input['status'] ?? Tenant::STATUS_TRIAL,
                'trial_ends_at' => ($input['status'] ?? Tenant::STATUS_TRIAL) === Tenant::STATUS_TRIAL
                    ? now()->addDays((int) ($input['trial_days'] ?? 14))
                    : null,
                'region_code' => $input['region_code'] ?? 'default',
                'preset_code' => $presetCode,
                'deployment_mode' => 'cloud',
                'contact_name' => $input['owner_name'],
                'contact_email' => $input['owner_email'],
                'locale' => $input['locale'] ?? 'en',
            ]));

            // Permissions before anything else — the owner needs a role to hold the moment they
            // exist, and every later step is audited against a real actor.
            app(RolesAndPermissionsSeeder::class)->run($tenant);

            $preset = $this->presets->apply($tenant, $presetCode);

            return $this->tenancy->runAs($tenant, function () use ($tenant, $input, $preset): array {
                $brand = Brand::query()->create([
                    'name' => $input['brand_name'] ?? $input['name'],
                    'code' => Str::upper(Str::substr(Str::slug($input['name'], ''), 0, 8)) ?: 'MAIN',
                    'is_default' => true,
                    'locale' => $input['locale'] ?? 'en',
                    'sender_name' => $input['brand_name'] ?? $input['name'],
                ]);

                $branch = Branch::query()->create([
                    'brand_id' => $brand->getKey(),
                    'name' => $input['branch_name'] ?? 'Main location',
                    'code' => 'MAIN',
                    // The single most consequential value on this form. A wrong timezone
                    // mis-schedules every session under it while looking entirely correct.
                    'timezone' => $input['timezone'],
                    'week_start' => $input['week_start'] ?? 'monday',
                    'weekend_days' => $input['weekend_days'] ?? ['saturday', 'sunday'],
                    'is_active' => true,
                ]);

                $owner = User::query()->create([
                    'name' => $input['owner_name'],
                    'email' => $input['owner_email'],
                    'password' => Hash::make($input['password'] ?? Str::password(16)),
                    'is_active' => true,
                    'scope_all_branches' => true,
                    'locale' => $input['locale'] ?? 'en',
                    'timezone' => $input['timezone'],
                ]);

                $owner->syncRoles([config('permissions.owner_role', 'Owner')]);

                return [
                    'tenant' => $tenant->refresh(),
                    'owner' => $owner->fresh(),
                    'brand' => $brand,
                    'branch' => $branch,
                    'preset' => $preset,
                ];
            });
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'account';
        $slug = $base;
        $suffix = 2;

        while ($this->tenancy->withoutScoping(fn () => Tenant::query()->where('slug', $slug)->exists())) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
