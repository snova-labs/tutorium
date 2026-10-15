<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the academy's own lists: changing it is a setting, reading it is everyday work.
 *
 * Every screen that schedules or takes a register needs to read the types, so viewing rides on the scheduling and attendance permissions as well as settings.
 *
 * @extends BasePolicy<SessionType>
 */
final class SessionTypePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'settings';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('settings.manage') || $user->can('batches.manage') || $user->can('attendance.record') || $user->can('learners.view'));
    }

    /** @param SessionType $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param SessionType $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param SessionType $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }
}
