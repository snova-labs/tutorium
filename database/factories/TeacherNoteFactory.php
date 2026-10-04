<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\NoteCategory;
use App\Models\TeacherNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherNote> */
final class TeacherNoteFactory extends Factory
{
    protected $model = TeacherNote::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'note_category_id' => NoteCategory::factory(),
            'body' => 'Working steadily and asking good questions.',
        ];
    }
}
