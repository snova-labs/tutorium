<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

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

    public function view(User $user, $record): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, $record): bool
    {
        return $this->allows($user, 'manage') && $this->inScope($user, $record);
    }

    public function delete(User $user, $record): bool
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
