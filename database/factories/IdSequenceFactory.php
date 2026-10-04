<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IdSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IdSequence> */
final class IdSequenceFactory extends Factory
{
    protected $model = IdSequence::class;

    public function definition(): array
    {
        return [
            'entity' => fake()->unique()->word(),
            'scope_type' => 'tenant',
            'scope_id' => null,
            'prefix' => 'REC',
            'separator' => '-',
            'pad_width' => 4,
            'next_number' => 1,
            'is_active' => true,
        ];
    }
}
