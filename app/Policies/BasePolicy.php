<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared authorization shape for tenant-owned resources.
 *
 * Two checks, always both: does the user hold the named permission, and is the record inside the
 * branches they may reach. Queries are already tenant-scoped underneath — this is the second
 * layer, not the only one (SL-SEC-004 §3.1).
 *
 * @template TModel of Model
 */
abstract class BasePolicy
{
    /** Permission prefix, e.g. "organisation" gives organisation.manage. */
    abstract protected function prefix(): string;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    /** @param TModel $record */
    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, 'view') && $this->inScope($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    /** @param TModel $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'update') && $this->inScope($user, $record);
    }

    /** @param TModel $record */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, 'delete') && $this->inScope($user, $record);
    }

    protected function allows(User $user, string $ability): bool
    {
        return $user->is_active && $user->can($this->permissionFor($ability));
    }

    protected function permissionFor(string $ability): string
    {
        return $this->prefix().'.'.$ability;
    }

    /**
     * Branch visibility. Records with no branch of their own are visible to anyone who cleared
     * the permission check; records that belong to a branch require access to that branch.
     */
    /** @param TModel $record */
    protected function inScope(User $user, Model $record): bool
    {
        $branchId = $record->getAttribute('branch_id')
            ?? ($record instanceof Branch ? $record->getKey() : null);

        if ($branchId === null) {
            return true;
        }

        return $user->canAccessBranch((int) $branchId);
    }
}
