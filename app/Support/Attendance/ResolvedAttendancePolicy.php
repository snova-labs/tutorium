<?php

declare(strict_types=1);

namespace App\Support\Attendance;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The attendance rules in force for a batch, together with where each came from.
 *
 * The provenance is not decoration. A teacher looking at a register needs to know whether the
 * ten-minute grace is this batch's decision or the whole academy's before they argue with it, and
 * an administrator changing it needs to know who else is affected (SL-ARC-002 §7).
 *
 * @implements Arrayable<string, mixed>
 */
final class ResolvedAttendancePolicy implements Arrayable
{
    /**
     * @param array<int, int>|null $countedSessionTypeIds null means every type counts
     * @param array<string, string> $origins setting name => level that supplied it
     */
    public function __construct(
        public readonly bool $isCompulsory,
        public readonly bool $allowLateJoin,
        public readonly int $lateGraceMinutes,
        public readonly ?array $countedSessionTypeIds,
        public readonly int $lowThresholdPct,
        public readonly array $origins,
    ) {}

    public function countsSessionType(int $sessionTypeId): bool
    {
        return $this->countedSessionTypeIds === null
            || in_array($sessionTypeId, $this->countedSessionTypeIds, true);
    }

    /**
     * Whether a mark counts as attendance under these rules.
     *
     * This is the one method that makes the policy mean something: a Late mark counts where late
     * joining is allowed and does not where it is not, without either case editing the mark.
     */
    public function treatsAsAttended(bool $countsAsAttended, bool $isLate): bool
    {
        if (! $countsAsAttended) {
            return false;
        }

        return ! $isLate || $this->allowLateJoin;
    }

    public function originOf(string $setting): string
    {
        return $this->origins[$setting] ?? 'system';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_compulsory' => $this->isCompulsory,
            'allow_late_join' => $this->allowLateJoin,
            'late_grace_min' => $this->lateGraceMinutes,
            'counted_session_type_ids' => $this->countedSessionTypeIds,
            'low_threshold_pct' => $this->lowThresholdPct,
            'origins' => $this->origins,
        ];
    }
}
