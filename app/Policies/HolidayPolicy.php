<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Closure dates belong to the organisation, like the branches they close.
 *
 * @extends BasePolicy<Holiday>
 */
final class HolidayPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'organisation';
    }

    public function viewAny(User $user): bool
    {
        // Whoever schedules classes needs to know which days a branch is closed.
        return $user->is_active && ($user->can('organisation.manage') || $user->can('batches.manage'));
    }

    /** @param Holiday $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user) && $this->inScope($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Holiday $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage') && $this->inScope($user, $record);
    }

    /** @param Holiday $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage') && $this->inScope($user, $record);
    }
}
