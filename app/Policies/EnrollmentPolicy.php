<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** @extends BasePolicy<Enrollment> */
final class EnrollmentPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'enrollments';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can('learners.view');
    }

    /** @param Enrollment $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user) && $user->can('view', $record->batch);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param Enrollment $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage') && $user->canAccessBranch((int) $record->batch->branch_id);
    }

    /** @param Enrollment $record */
    public function delete(User $user, Model $record): bool
    {
        // Enrollments are withdrawn, never deleted. The record that someone attended for six weeks
        // is exactly what the reports were built on.
        return false;
    }

    public function transfer(User $user, Enrollment $enrollment): bool
    {
        return $this->update($user, $enrollment);
    }
}
