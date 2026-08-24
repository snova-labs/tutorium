<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
final class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'date' => '2026-08-15',
            'name' => 'Sample public holiday',
            'blocks_sessions' => true,
        ];
    }
}
