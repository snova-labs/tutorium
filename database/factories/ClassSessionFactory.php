<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\SessionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassSession> */
final class ClassSessionFactory extends Factory
{
    protected $model = ClassSession::class;

    public function definition(): array
    {
        $starts = CarbonImmutable::parse('2026-08-01 09:00:00', 'UTC');

        return [
            'batch_id' => Batch::factory(),
            'session_type_id' => SessionType::factory(),
            'session_local_date' => $starts->toDateString(),
            'start_time_local' => '09:00:00',
            'starts_at_utc' => $starts,
            'ends_at_utc' => $starts->addMinutes(120),
            'status' => SessionStatus::Scheduled,
        ];
    }

    public function held(): self
    {
        return $this->state(fn () => ['status' => SessionStatus::Held]);
    }

    public function cancelled(string $reason = 'Teacher illness'): self
    {
        return $this->state(fn () => ['status' => SessionStatus::Cancelled, 'cancel_reason' => $reason]);
    }
}
