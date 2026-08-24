<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Settings\SettingsResolver;

/**
 * Guardians are a module a tenant can switch off entirely.
 *
 * An IT institute teaching adults has no guardians, and showing them the concept at all is noise.
 * The check reads the resolved setting rather than a hard-coded assumption about who the customer
 * teaches (FR-CFG-5).
 */
final class GuardianPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'guardians';
    }

    public function viewAny(User $user): bool
    {
        return $this->moduleEnabled() && $user->is_active && $user->can('learners.view');
    }

    public function view(User $user, $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->moduleEnabled() && $this->allows($user, 'manage');
    }

    public function update(User $user, $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, $record): bool
    {
        return $this->create($user);
    }

    private function moduleEnabled(): bool
    {
        return (bool) app(SettingsResolver::class)->get('people.guardians_enabled', null, true);
    }
}
