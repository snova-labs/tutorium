<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\RecipientType;
use App\Models\Report;
use App\Models\ReportDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportDelivery> */
final class ReportDeliveryFactory extends Factory
{
    protected $model = ReportDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'recipient_type' => RecipientType::Guardian,
            'recipient_name' => 'Sample Guardian',
            'to_address' => 'guardian@sample.test',
            'status' => DeliveryStatus::Queued,
        ];
    }
}
