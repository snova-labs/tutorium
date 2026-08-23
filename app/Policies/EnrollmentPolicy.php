<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

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

    public function view(User $user, $record): bool
    {
        return $this->viewAny($user) && $user->can('view', $record->batch);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, $record): bool
    {
        return $this->allows($user, 'manage') && $user->canAccessBranch((int) $record->batch->branch_id);
    }

    public function delete(User $user, $record): bool
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
