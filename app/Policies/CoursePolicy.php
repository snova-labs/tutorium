<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** @extends BasePolicy<Course> */
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

    /** @param Course $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Course $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Course $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }
}
