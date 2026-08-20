<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'attendance.compulsory',
            'value' => true,
            'scope_type' => 'tenant',
            'scope_id' => null,
        ];
    }
}
