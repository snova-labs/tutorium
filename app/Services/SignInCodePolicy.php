<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Which academy staff must enter an emailed code at sign-in.
 *
 * Decided by role, per academy (the "security.sign_in_code_roles" setting). The default covers the
 * roles that can see money or change who has access.
 */
final class SignInCodePolicy
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SettingsResolver $settings,
    ) {}

    public function requiresCode(User $user): bool
    {
        $tenant = $user->tenant;

        if ($tenant === null) {
            return true;
        }

        // Roles and settings both belong to the user's academy, which is not bound at sign-in.
        return $this->tenancy->runAs($tenant, function () use ($user): bool {
            $this->settings->flush();
            $roles = $this->settings->get('security.sign_in_code_roles');
            $this->settings->flush();

            if (! is_array($roles) || $roles === []) {
                return false;
            }

            return $user->unsetRelation('roles')->hasAnyRole(array_values(array_filter($roles, 'is_string')));
        });
    }
}
