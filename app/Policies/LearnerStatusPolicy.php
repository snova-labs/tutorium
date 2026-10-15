<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LearnerStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the academy's own lists: changing it is a setting, reading it is everyday work.
 *
 * Anyone who can see learners needs to read the statuses to make sense of a learner's record.
 *
 * @extends BasePolicy<LearnerStatus>
 */
final class LearnerStatusPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'settings';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('settings.manage') || $user->can('learners.view'));
    }

    /** @param LearnerStatus $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param LearnerStatus $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param LearnerStatus $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }
}
