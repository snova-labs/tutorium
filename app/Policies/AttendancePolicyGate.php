<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\User;

/**
 * Who may read and write a register.
 *
 * Registered against ClassSession alongside ClassSessionPolicy's scheduling abilities, because
 * recording attendance and rescheduling a class are different jobs held by different people: a
 * teacher marks the register they cannot move.
 */
final class AttendancePolicyGate
{
    public function viewRoster(User $user, ClassSession $session): bool
    {
        return $user->is_active
            && $user->can('attendance.view')
            && $user->can('view', $session->batch);
    }

    public function record(User $user, ClassSession $session): bool
    {
        if (! $user->is_active || ! $user->can('attendance.record')) {
            return false;
        }

        if (! $user->can('view', $session->batch)) {
            return false;
        }

        // Amending a register after its reporting period has closed is a separate permission,
        // because a report has already been sent on the strength of those marks.
        $period = $session->batch->reportingPeriods()
            ->whereDate('starts_local_date', '<=', $session->session_local_date->toDateString())
            ->whereDate('ends_local_date', '>=', $session->session_local_date->toDateString())
            ->first();

        if ($period !== null && ! $period->acceptsRoutineEdits()) {
            return $user->can('attendance.amend');
        }

        return true;
    }
}
