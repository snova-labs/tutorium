<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** @extends BasePolicy<ReportingPeriod> */
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

    /** @param ReportingPeriod $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param ReportingPeriod $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }
}
