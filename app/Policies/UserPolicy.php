<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'users';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function view(User $user, $record): bool
    {
        // Everyone may read their own record without holding an administrative permission.
        return $user->is($record) || $this->allows($user, 'manage');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, $record): bool
    {
        return $user->is($record) || $this->allows($user, 'manage');
    }

    public function delete(User $user, $record): bool
    {
        // Removing your own account by accident, in a product where you may be the only
        // administrator, is a mistake worth making impossible rather than reversible.
        return ! $user->is($record) && $this->allows($user, 'manage');
    }
}
