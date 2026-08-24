<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Batch> */
final class BatchFactory extends Factory
{
    protected $model = Batch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'branch_id' => Branch::factory(),
            'name' => 'Sample Batch',
            'code' => strtoupper(fake()->unique()->bothify('BAT##??')),
            'timezone' => 'UTC',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-12-31',
            'capacity' => 30,
            'delivery_mode' => DeliveryMode::InPerson,
            'status' => BatchStatus::Running,
        ];
    }

    /** The daylight-saving fixture. Clocks go back on 1 November 2026. */
    public function toronto(): self
    {
        return $this->state(fn () => ['timezone' => 'America/Toronto']);
    }

    /** A +05:45 offset — the case that catches code assuming whole-hour zones. */
    public function kathmandu(): self
    {
        return $this->state(fn () => ['timezone' => 'Asia/Kathmandu']);
    }

    /** Southern-hemisphere daylight saving, which moves the opposite way. */
    public function sydney(): self
    {
        return $this->state(fn () => ['timezone' => 'Australia/Sydney']);
    }
}
