<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusHistory;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\UsageSnapshot;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Counting what a customer pays for.
 *
 * The definition, in full: an **active learner** is a person with at least one enrollment whose
 * status is flagged `is_active_for_billing`. Counted once, however many classes they are in. The
 * billable quantity for a period is the **peak** daily count, not the average and not the closing
 * figure (SL-BIL-006 §2).
 *
 * Three consequences worth stating because customers ask about all of them:
 *  - A learner who joins and leaves inside one month is counted for that month. They used it.
 *  - Archived, completed and withdrawn learners are never counted. History costs nothing, because
 *    charging for archives only teaches people to delete the evidence their reports rest on.
 *  - Sample data is excluded, because nobody should pay to evaluate the product.
 */
final class MeteringService
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /** Take today's count for one account. Safe to run twice; the second run changes nothing. */
    public function snapshot(Tenant $tenant, ?CarbonImmutable $date = null): UsageSnapshot
    {
        $date ??= CarbonImmutable::now()->startOfDay();

        return $this->tenancy->runAs($tenant, function () use ($date): UsageSnapshot {
            $existing = UsageSnapshot::query()
                ->where('snapshot_date', $date->toDateString())
                ->where('is_correction', false)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $breakdown = $this->countByBranch();

            return UsageSnapshot::query()->create([
                'snapshot_date' => $date->toDateString(),
                'active_learners' => array_sum($breakdown),
                'breakdown' => $breakdown,
            ]);
        });
    }

    /** @return array<string, int> */
    public function snapshotAll(?CarbonImmutable $date = null): array
    {
        $results = [];

        $tenants = $this->tenancy->withoutScoping(
            fn () => Tenant::query()->whereIn('status', [
                Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE,
                Tenant::STATUS_PAST_DUE, Tenant::STATUS_SUSPENDED,
            ])->get()
        );

        foreach ($tenants as $tenant) {
            $results[$tenant->slug] = $this->snapshot($tenant, $date)->active_learners;
        }

        return $results;
    }

    /**
     * The billable figure for a period, and the day it came from.
     *
     * @return array{quantity: int, snapshot: ?UsageSnapshot, days_measured: int, missing_days: array<int, string>}
     */
    public function peakFor(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->tenancy->runAs($tenant, function () use ($from, $to): array {
            $snapshots = $this->effectiveSnapshots($from, $to);

            $peak = $snapshots->sortByDesc('active_learners')->first();

            return [
                'quantity' => $peak?->active_learners ?? 0,
                'snapshot' => $peak,
                'days_measured' => $snapshots->count(),
                'missing_days' => $this->missingDays($snapshots, $from, $to),
            ];
        });
    }

    /**
     * Reconstruct a day that was never measured.
     *
     * Reads enrollment status history rather than guessing from today's figures — which is the
     * entire reason that table stores rows instead of a single current status. A backfilled day is
     * recorded as a correction so it is never mistaken for a live measurement.
     */
    public function backfill(Tenant $tenant, CarbonImmutable $date, ?Operator $operator = null): UsageSnapshot
    {
        return $this->tenancy->runAs($tenant, function () use ($date, $operator): UsageSnapshot {
            $billableIds = EnrollmentStatus::query()->billable()->pluck('id');

            $count = EnrollmentStatusHistory::query()
                ->select('enrollment_id')
                ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(to_status_id ORDER BY changed_at DESC), ",", 1) as status_at_date')
                ->where('changed_at', '<=', $date->endOfDay())
                ->groupBy('enrollment_id')
                ->get()
                ->filter(fn ($row) => $billableIds->contains((int) $row->status_at_date))
                ->pluck('enrollment_id');

            $learners = Enrollment::query()
                ->whereIn('id', $count)
                ->distinct('learner_id')
                ->count('learner_id');

            return UsageSnapshot::query()->create([
                'snapshot_date' => $date->toDateString(),
                'active_learners' => $learners,
                'breakdown' => null,
                'is_correction' => true,
                'correction_reason' => 'Backfilled from enrollment history — no measurement was taken that day.',
                'corrected_by_operator_id' => $operator?->getKey(),
            ]);
        });
    }

    /**
     * Correct a measured day.
     *
     * Writes a new row. The original stays readable, and any invoice built from it says which
     * figure it used — because "we changed the number, trust us" is not an answer (FR-MTR-4).
     */
    public function correct(
        Tenant $tenant,
        UsageSnapshot $original,
        int $corrected,
        string $reason,
        Operator $operator,
    ): UsageSnapshot {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why the measured figure was wrong. This appears on the customer\'s invoice.',
            ]);
        }

        return $this->tenancy->runAs($tenant, fn () => DB::transaction(
            fn () => UsageSnapshot::query()->create([
                'snapshot_date' => $original->snapshot_date->toDateString(),
                'active_learners' => $corrected,
                'breakdown' => $original->breakdown,
                'is_correction' => true,
                'corrects_snapshot_id' => $original->getKey(),
                'correction_reason' => $reason,
                'corrected_by_operator_id' => $operator->getKey(),
            ])
        ));
    }

    /**
     * The live meter a customer sees every day of the month.
     *
     * The invoice should never be the first time someone meets the number (FR-MTR-3).
     *
     * @return array<string, mixed>
     */
    public function liveMeter(Tenant $tenant, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $from = $at->startOfMonth();
        $to = $at->endOfMonth();

        return $this->tenancy->runAs($tenant, function () use ($from, $to, $at): array {
            $snapshots = $this->effectiveSnapshots($from, $to);
            $peak = $snapshots->sortByDesc('active_learners')->first();
            $today = array_sum($this->countByBranch());

            return [
                'today' => $today,
                'peak' => max($peak?->active_learners ?? 0, $today),
                'peak_on' => $peak?->snapshot_date->toDateString(),
                'will_be_billed_for' => max($peak?->active_learners ?? 0, $today),
                'basis' => 'The highest daily count in the period, not the average and not the closing figure.',
                'period' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
                'daily' => $snapshots->map(fn (UsageSnapshot $s) => [
                    'date' => $s->snapshot_date->toDateString(),
                    'count' => $s->active_learners,
                    'corrected' => $s->is_correction,
                ])->values(),
                'measured_through' => $at->toDateString(),
            ];
        });
    }

    /**
     * Snapshots with corrections taking precedence over the originals they replace.
     *
     * @return \Illuminate\Support\Collection<int, UsageSnapshot>
     */
    private function effectiveSnapshots(CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        return UsageSnapshot::query()
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('snapshot_date')
            ->orderBy('id')
            ->get()
            // Later row wins for a given day, and corrections are always written later.
            ->keyBy(fn (UsageSnapshot $s) => $s->snapshot_date->toDateString())
            ->values();
    }

    /**
     * Today's count, per branch.
     *
     * @return array<string, int>
     */
    private function countByBranch(): array
    {
        $rows = Enrollment::query()
            ->billable()
            ->join('batches', 'batches.id', '=', 'enrollments.batch_id')
            ->join('branches', 'branches.id', '=', 'batches.branch_id')
            // Distinct on the learner, not the enrollment: three classes is one person.
            ->select('branches.code', 'enrollments.learner_id')
            ->distinct()
            ->get();

        $counted = [];
        $seen = [];

        foreach ($rows as $row) {
            if (isset($seen[$row->learner_id])) {
                continue;
            }

            $seen[$row->learner_id] = true;
            $counted[$row->code] = ($counted[$row->code] ?? 0) + 1;
        }

        return $counted;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, UsageSnapshot>  $snapshots
     * @return array<int, string>
     */
    private function missingDays(\Illuminate\Support\Collection $snapshots, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $have = $snapshots->map(fn (UsageSnapshot $s) => $s->snapshot_date->toDateString())->flip();
        $missing = [];
        $cursor = $from->startOfDay();
        $last = min($to, CarbonImmutable::now())->startOfDay();

        while ($cursor <= $last) {
            if (! $have->has($cursor->toDateString())) {
                $missing[] = $cursor->toDateString();
            }

            $cursor = $cursor->addDay();
        }

        return $missing;
    }
}
