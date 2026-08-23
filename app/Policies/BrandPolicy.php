<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

/**
 * Brands are account-wide identity, so they sit behind a single administrative permission rather
 * than a create/update/delete family — a role either runs the organisation or it does not.
 */
final class BrandPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'organisation';
    }

    public function viewAny(User $user): bool
    {
        // Anyone who can see learners needs to know which brand they belong to.
        return $user->is_active && ($user->can('organisation.manage') || $user->can('learners.view'));
    }

    public function view(User $user, $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, $record): bool
    {
        return $this->allows($user, 'manage');
    }

    public function delete(User $user, $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /**
     * A brand still holding branches is archived, never deleted. Blocking the destructive path
     * outright is clearer than warning about it and then allowing it anyway.
     */
    public function forceDelete(User $user, Brand $brand): bool
    {
        return $this->allows($user, 'manage') && $brand->branches()->doesntExist();
    }
}
