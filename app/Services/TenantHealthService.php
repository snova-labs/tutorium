<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\ReportDelivery;
use App\Models\Tenant;
use App\Support\Platform\TenantHealth;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Health as measured, not as reported.
 *
 * An account on the largest plan that has not taken a register in eleven days is in trouble, and
 * one on the smallest plan using the product every week is fine. Deriving this from activity
 * rather than from revenue is what makes the directory worth looking at.
 */
final class TenantHealthService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly OnboardingChecklist $checklist,
    ) {}

    public function for(Tenant $tenant): TenantHealth
    {
        return $this->tenancy->runAs($tenant, function () use ($tenant): TenantHealth {
            $lastActivity = AuditLog::query()->max('occurred_at');
            $lastRegister = AttendanceRecord::query()->max('recorded_at');
            $activeLearners = Enrollment::query()->billable()->distinct('learner_id')->count('learner_id');
            $failedDeliveries = ReportDelivery::query()->whereIn('status', ['failed', 'bounced'])->count();

            $signals = [];
            $state = TenantHealth::HEALTHY;

            $daysSinceRegister = $lastRegister === null
                ? null
                : now()->diffInDays(CarbonImmutable::parse($lastRegister));

            if ($tenant->status === Tenant::STATUS_TRIAL) {
                $onboarding = $this->checklist->for($tenant);

                if (! $onboarding['can_record_attendance']) {
                    $daysLeft = $tenant->trial_ends_at === null
                        ? null
                        : (int) now()->diffInDays($tenant->trial_ends_at, false);

                    // The signal that matters most in a trial: they have not reached the point
                    // where the product does anything for them.
                    $state = TenantHealth::STALLED;
                    $signals[] = sprintf(
                        'Setup stopped at step %d of %d%s.',
                        $onboarding['done'] + 1,
                        $onboarding['total'],
                        $daysLeft === null ? '' : ", trial ends in {$daysLeft} days",
                    );
                }
            }

            if ($activeLearners === 0 && $state === TenantHealth::HEALTHY) {
                $state = TenantHealth::STALLED;
                $signals[] = 'Nobody enrolled yet.';
            }

            if ($daysSinceRegister === null && $activeLearners > 0) {
                $state = TenantHealth::QUIET;
                $signals[] = 'Learners enrolled, but no attendance ever recorded.';
            } elseif ($daysSinceRegister !== null && $daysSinceRegister >= 10) {
                $state = TenantHealth::QUIET;
                $signals[] = "No attendance recorded in {$daysSinceRegister} days.";
            }

            if ($failedDeliveries > 0) {
                $signals[] = $failedDeliveries.' report '
                    .($failedDeliveries === 1 ? 'delivery has' : 'deliveries have').' failed.';

                if ($state === TenantHealth::HEALTHY) {
                    $state = TenantHealth::QUIET;
                }
            }

            if ($tenant->status === Tenant::STATUS_PAST_DUE) {
                $state = TenantHealth::AT_RISK;
                $signals[] = 'Payment overdue.';
            }

            return new TenantHealth(
                state: $state,
                signals: $signals,
                lastActivityUtc: $lastActivity === null
                    ? null
                    : CarbonImmutable::parse($lastActivity)->toIso8601String(),
                activeLearners: $activeLearners,
                failedDeliveries: $failedDeliveries,
            );
        });
    }
}
