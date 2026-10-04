<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Guardians are a module a tenant can switch off entirely.
 *
 * An IT institute teaching adults has no guardians, and showing them the concept at all is noise.
 * The check reads the resolved setting rather than a hard-coded assumption about who the customer
 * teaches (FR-CFG-5).
 *
 * @extends BasePolicy<Guardian>
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

    /** @param Guardian $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->moduleEnabled() && $this->allows($user, 'manage');
    }

    /** @param Guardian $record */
    public function update(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    /** @param Guardian $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    private function moduleEnabled(): bool
    {
        return (bool) app(SettingsResolver::class)->get('people.guardians_enabled', null, true);
    }
}
