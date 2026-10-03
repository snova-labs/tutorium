<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportRunStatus;
use App\Models\Batch;
use App\Models\ReportingPeriod;
use App\Models\ReportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportRun> */
final class ReportRunFactory extends Factory
{
    protected $model = ReportRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'reporting_period_id' => ReportingPeriod::factory(),
            'status' => ReportRunStatus::Queued,
        ];
    }
}
