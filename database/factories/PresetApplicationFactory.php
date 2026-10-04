<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PresetApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PresetApplication> */
final class PresetApplicationFactory extends Factory
{
    protected $model = PresetApplication::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'preset_code' => 'blank',
            'version' => 1,
            'applied_at' => now(),
        ];
    }
}
