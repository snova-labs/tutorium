<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\LearnerStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Learner> */
final class LearnerFactory extends Factory
{
    protected $model = Learner::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 9999);

        return [
            // Neutral by policy: no real learner name ever appears in code, seeds or fixtures.
            'legal_name' => "Sample Learner {$n}",
            'preferred_name' => null,
            'number' => 'STU-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'date_of_birth' => '2014-05-12',
            'country' => 'NP',
            'status_id' => LearnerStatus::factory(),
            'status_changed_on' => now()->toDateString(),
        ];
    }

    /** A learner abroad, for exercising the multi-timezone path. */
    public function overseas(): self
    {
        return $this->state(fn () => ['country' => 'CA', 'home_timezone' => 'America/Toronto']);
    }
}
