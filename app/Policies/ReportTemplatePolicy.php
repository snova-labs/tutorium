<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What a report contains is a setting, so it sits behind the settings permission.
 *
 * @extends BasePolicy<ReportTemplate>
 */
final class ReportTemplatePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'settings';
    }

    public function viewAny(User $user): bool
    {
        // Whoever generates reports may look at which template applies, without being able to
        // change what every parent receives.
        return $user->is_active && ($user->can('settings.manage') || $user->can('reports.generate'));
    }

    /** @param ReportTemplate $record */
    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    /** @param ReportTemplate $record */
    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /**
     * Never. A sent report points at the template it was rendered from, and deleting one would
     * change what a re-download of that report looks like.
     *
     * @param ReportTemplate $record
     */
    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
