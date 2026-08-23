<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class CoursePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'courses';
    }

    public function viewAny(User $user): bool
    {
        // Teachers need to see the course their batch belongs to without being able to edit it.
        return $user->is_active && ($user->can('courses.manage') || $user->can('learners.view'));
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
}
