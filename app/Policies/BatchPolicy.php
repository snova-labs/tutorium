<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

/**
 * Batch access has a third dimension beyond permission and branch: assignment.
 *
 * A teacher holding grades.enter still reaches only the batches they teach. That is the rule the
 * whole teaching side of the product depends on (FR-IAM-3).
 */
final class BatchPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'batches';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('batches.manage') || $user->can('learners.view'));
    }

    public function view(User $user, $record): bool
    {
        return $this->viewAny($user) && $this->reaches($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, $record): bool
    {
        return $this->allows($user, 'manage') && $this->reaches($user, $record);
    }

    public function delete(User $user, $record): bool
    {
        return $this->allows($user, 'manage') && $this->reaches($user, $record);
    }

    /** Generating and cancelling sessions is scheduling, not batch administration. */
    public function schedule(User $user, Batch $batch): bool
    {
        return $user->is_active && $user->can('sessions.manage') && $this->reaches($user, $batch);
    }

    public function teach(User $user, Batch $batch): bool
    {
        return $user->is_active && $this->isAssigned($user, $batch);
    }

    private function reaches(User $user, Batch $batch): bool
    {
        if (! $user->canAccessBranch((int) $batch->branch_id)) {
            return false;
        }

        // Anyone who can administer batches sees every batch in their branches; anyone who cannot
        // sees only their own.
        if ($user->can('batches.manage')) {
            return true;
        }

        return $this->isAssigned($user, $batch);
    }

    private function isAssigned(User $user, Batch $batch): bool
    {
        return $batch->teachers()->whereKey($user->getKey())->exists();
    }
}
