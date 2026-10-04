<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PeriodType;
use App\Models\Batch;
use App\Models\ReportingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportingPeriod> */
final class ReportingPeriodFactory extends Factory
{
    protected $model = ReportingPeriod::class;

    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'course_id' => null,
            'type' => PeriodType::Term,
            'label' => 'Autumn 2026',
            'starts_local_date' => '2026-09-01',
            'ends_local_date' => '2026-12-15',
            'status' => ReportingPeriod::STATUS_OPEN,
        ];
    }
}
