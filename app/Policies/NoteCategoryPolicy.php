<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NoteCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the academy's own lists: changing it is a setting, reading it is everyday work.
 *
 * A teacher filing a note must be able to read the categories, without being able to change the list for everyone.
 *
 * @extends BasePolicy<NoteCategory>
 */
final class NoteCategoryPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'settings';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('settings.manage') || $user->can('notes.view'));
    }

    /** @param NoteCategory $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param NoteCategory $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param NoteCategory $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }
}
