<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\User;

final class ClassSessionPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'sessions';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('sessions.manage') || $user->can('attendance.view'));
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
        return $this->allows($user, 'manage') && $user->can('schedule', $record->batch);
    }

    public function delete(User $user, $record): bool
    {
        return false; // Sessions are cancelled, never deleted — see SchedulingService::cancel().
    }

    public function cancel(User $user, ClassSession $session): bool
    {
        return $this->allows($user, 'manage') && $user->can('schedule', $session->batch);
    }
}
