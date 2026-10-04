<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invitation> */
final class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => 'Sample Colleague',
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'role_name' => 'Teacher',
            'expires_at' => now()->addDays(7),
        ];
    }
}
