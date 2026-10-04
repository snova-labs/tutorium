<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SessionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SessionType> */
final class SessionTypeFactory extends Factory
{
    protected $model = SessionType::class;

    public function definition(): array
    {
        return [
            'course_id' => null,
            'name' => 'Class',
            'code' => strtoupper(fake()->unique()->bothify('ST##??')),
            'counts_in_attendance' => true,
            'color' => '#334155',
            'sort' => 0,
            'is_active' => true,
        ];
    }

    /** A make-up class is recorded but must not inflate the percentage. */
    public function makeUp(): self
    {
        return $this->state(fn () => ['name' => 'Make-up', 'counts_in_attendance' => false]);
    }
}
