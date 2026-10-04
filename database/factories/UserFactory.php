<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => 'Sample Staff Member',
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'locale' => 'en',
            'is_active' => true,
            'scope_all_branches' => false,
        ];
    }

    public function owner(): self
    {
        return $this->state(fn () => [
            'name' => 'Sample Owner',
            'scope_all_branches' => true,
        ]);
    }
}
