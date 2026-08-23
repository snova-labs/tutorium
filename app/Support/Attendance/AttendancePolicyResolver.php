<?php

declare(strict_types=1);

namespace App\Support\Attendance;

use App\Models\AttendancePolicy;
use App\Models\Batch;
use App\Support\Settings\SettingsResolver;

/**
 * Resolves the attendance rules for a batch: batch override → course override → system defaults.
 *
 * Policies resolve as whole units rather than key by key, because a half-applied override is how
 * a batch ends up allowing late joining with a grace period nobody set.
 */
final class AttendancePolicyResolver
{
    /** @var array<int, ResolvedAttendancePolicy> */
    private array $cache = [];

    public function __construct(private readonly SettingsResolver $settings) {}

    public function for(Batch $batch): ResolvedAttendancePolicy
    {
        return $this->cache[$batch->getKey()] ??= $this->resolve($batch);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function resolve(Batch $batch): ResolvedAttendancePolicy
    {
        $batchPolicy = AttendancePolicy::query()
            ->where('scope_type', 'batch')->where('scope_id', $batch->getKey())->first();

        $coursePolicy = AttendancePolicy::query()
            ->where('scope_type', 'course')->where('scope_id', $batch->course_id)->first();

        $origins = [];

        $pick = function (string $key, mixed $default) use ($batchPolicy, $coursePolicy, &$origins): mixed {
            if ($batchPolicy !== null && $batchPolicy->getAttribute($key) !== null) {
                $origins[$key] = 'batch';

                return $batchPolicy->getAttribute($key);
            }

            if ($coursePolicy !== null && $coursePolicy->getAttribute($key) !== null) {
                $origins[$key] = 'course';

                return $coursePolicy->getAttribute($key);
            }

            $origins[$key] = 'tenant';

            return $default;
        };

        return new ResolvedAttendancePolicy(
            isCompulsory: (bool) $pick('is_compulsory', $this->settings->get('attendance.compulsory', null, true)),
            allowLateJoin: (bool) $pick('allow_late_join', $this->settings->get('attendance.allow_late_join', null, true)),
            lateGraceMinutes: (int) $pick('late_grace_min', $this->settings->get('attendance.late_grace_minutes', null, 10)),
            countedSessionTypeIds: $pick('counted_session_type_ids', null),
            lowThresholdPct: (int) $pick('low_threshold_pct', $this->settings->get('attendance.low_threshold_pct', null, 75)),
            origins: $origins,
        );
    }
}
