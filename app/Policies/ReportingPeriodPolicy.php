<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class ReportingPeriodPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'settings';
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can('reports.generate') || $user->can('settings.manage'));
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
}
