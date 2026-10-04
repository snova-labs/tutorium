<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Brands are account-wide identity, so they sit behind a single administrative permission rather
 * than a create/update/delete family — a role either runs the organisation or it does not.
 *
 * @extends BasePolicy<Brand>
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

    /** @param Brand $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Brand $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Brand $record */
    public function delete(User $user, Model $record): bool
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
