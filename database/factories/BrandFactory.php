<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Brand> */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Sample Brand',
            'code' => strtoupper(fake()->unique()->bothify('BR##??')),
            'colors' => ['primary' => '#334155', 'accent' => '#D97706'],
            'sender_name' => 'Sample Brand',
            'sender_email' => fake()->unique()->safeEmail(),
            'locale' => 'en',
            'is_default' => true,
        ];
    }
}
