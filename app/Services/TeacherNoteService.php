<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\NoteCategory;
use App\Models\TeacherNote;
use App\Support\Time\PeriodService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class TeacherNoteService
{
    public function __construct(private readonly PeriodService $periods) {}

    /** @param array<string, mixed> $attributes */
    public function write(Enrollment $enrollment, array $attributes): TeacherNote
    {
        return DB::transaction(function () use ($enrollment, $attributes): TeacherNote {
            $category = NoteCategory::query()->findOrFail($attributes['note_category_id']);

            $period = $this->periods->ensure(
                $enrollment->batch,
                isset($attributes['period'])
                    ? $this->periods->forLabel($enrollment->batch, $attributes['period'])
                    : $this->periods->current($enrollment->batch),
            );

            return TeacherNote::query()->create([
                'enrollment_id' => $enrollment->getKey(),
                'reporting_period_id' => $period->getKey(),
                'note_category_id' => $category->getKey(),
                'body' => $attributes['body'],
                // The category's default applies unless the teacher says otherwise, so a Behaviour
                // note does not reach a parent because someone forgot to untick a box.
                'is_report_visible' => $attributes['is_report_visible'] ?? $category->report_visible_default,
                'author_id' => Auth::id(),
            ]);
        });
    }

    /**
     * Write a note for a whole batch in one pass, skipping the empty ones.
     *
     * @param  array<int, array{enrollment_id: int, body: string, note_category_id: int, is_report_visible?: bool}>  $notes
     * @return array{written: int, skipped: int}
     */
    public function writeMany(array $notes, ?string $period = null): array
    {
        $written = 0;
        $skipped = 0;

        DB::transaction(function () use ($notes, $period, &$written, &$skipped): void {
            foreach ($notes as $note) {
                if (trim((string) ($note['body'] ?? '')) === '') {
                    // An empty box is a teacher who has not got to that learner yet, not a note
                    // saying nothing.
                    $skipped++;

                    continue;
                }

                $enrollment = Enrollment::query()->with('batch.course')->findOrFail($note['enrollment_id']);

                $this->write($enrollment, [
                    'note_category_id' => $note['note_category_id'],
                    'body' => $note['body'],
                    'is_report_visible' => $note['is_report_visible'] ?? null,
                    'period' => $period,
                ]);

                $written++;
            }
        });

        return ['written' => $written, 'skipped' => $skipped];
    }
}
