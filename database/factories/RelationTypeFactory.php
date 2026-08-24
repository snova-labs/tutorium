<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RelationType> */
final class RelationTypeFactory extends Factory
{
    protected $model = RelationType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Guardian',
            'code' => 'GUARDIAN'.fake()->unique()->numberBetween(1, 9999),
            'sort' => 0,
        ];
    }
}
