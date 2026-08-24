<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendancePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendancePolicy> */
final class AttendancePolicyFactory extends Factory
{
    protected $model = AttendancePolicy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope_type' => 'batch',
            'scope_id' => 1,
            'is_compulsory' => true,
            'allow_late_join' => true,
            'late_grace_min' => 10,
            'low_threshold_pct' => 75,
        ];
    }
}
