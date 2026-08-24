<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Batch;
use App\Models\SessionType;
use App\Models\TimetableSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimetableSlot> */
final class TimetableSlotFactory extends Factory
{
    protected $model = TimetableSlot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'batch_id' => Batch::factory(),
            'session_type_id' => SessionType::factory(),
            'weekday' => Weekday::Saturday,
            'start_time_local' => '09:00:00',
            'duration_min' => 120,
        ];
    }

    public function on(Weekday $day, string $time = '09:00:00'): self
    {
        return $this->state(fn () => ['weekday' => $day, 'start_time_local' => $time]);
    }
}
