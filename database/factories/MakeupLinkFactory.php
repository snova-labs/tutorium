<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\MakeupLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MakeupLink> */
final class MakeupLinkFactory extends Factory
{
    protected $model = MakeupLink::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'missed_session_id' => ClassSession::factory(),
            'makeup_session_id' => ClassSession::factory(),
        ];
    }
}
