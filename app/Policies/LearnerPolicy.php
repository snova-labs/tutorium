<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Learner;
use App\Models\User;

final class LearnerPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'learners';
    }

    public function archive(User $user, Learner $learner): bool
    {
        return $this->allows($user, 'archive');
    }

    /**
     * Permanent deletion is a data-protection action, not an administrative one, and is separated
     * from archiving so that a role can be given one without the other.
     */
    public function forceDelete(User $user, Learner $learner): bool
    {
        return $this->allows($user, 'delete') && $learner->enrollments()->doesntExist();
    }
}
