<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** @extends BasePolicy<Branch> */
final class BranchPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'organisation';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('organisation.manage') || $user->can('learners.view'));
    }

    /** @param Branch $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Branch $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage') && $this->inScope($user, $record);
    }

    /** @param Branch $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage') && $this->inScope($user, $record);
    }

    public function forceDelete(User $user, Branch $branch): bool
    {
        // Reserved for branches that never held anything. Once academic history exists the only
        // path is archival, enforced in BranchService.
        return $this->allows($user, 'manage') && $branch->users()->doesntExist();
    }
}
