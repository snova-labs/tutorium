<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Report;
use App\Models\ReportingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
final class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'reporting_period_id' => ReportingPeriod::factory(),
            'number' => 'RPT-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'stats_snapshot' => [],
            'generated_at' => now(),
        ];
    }
}
