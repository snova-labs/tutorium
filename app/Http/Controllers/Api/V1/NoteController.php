<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\NoteCategory;
use App\Models\TeacherNote;
use App\Services\TeacherNoteService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class NoteController
{
    public function __construct(private readonly TeacherNoteService $notes) {}

    public function index(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless($request->user()->can('view', $enrollment), 403);
        abort_unless($request->user()->can('notes.view'), 403);

        $query = $enrollment->notes()->with(['category', 'author', 'period'])->latest();

        // Internal notes need their own permission. Half of what a good teacher records is
        // context that helps colleagues and would be unkind to send home.
        if (! $request->user()->can('notes.view_internal')) {
            $query->visibleOnReports();
        }

        return response()->json([
            'data' => $query->get()->map(fn (TeacherNote $note) => [
                'id' => $note->getKey(),
                'category' => $note->category->name,
                'period' => $note->period?->label,
                'body' => $note->body,
                'is_report_visible' => $note->is_report_visible,
                'author' => $note->author?->name,
                'written_at_utc' => $note->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless($request->user()->can('view', $enrollment), 403);
        abort_unless($request->user()->can('notes.write'), 403);

        $validated = $request->validate([
            'note_category_id' => ['required', 'integer', TenantRule::exists('note_categories')],
            'body' => ['required', 'string', 'max:4000'],
            'is_report_visible' => ['nullable', 'boolean'],
            'period' => ['nullable', 'string', 'max:60'],
        ]);

        $note = $this->notes->write($enrollment->load('batch.course'), $validated);

        return response()->json([
            'data' => [
                'id' => $note->getKey(),
                'is_report_visible' => $note->is_report_visible,
                'message' => $note->is_report_visible
                    ? 'Saved. This will appear on the report.'
                    : 'Saved as an internal note. It will not appear on the report.',
            ],
        ], 201);
    }

    /** Write for a whole batch in one pass — the screen a teacher uses at period end. */
    public function storeMany(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);
        abort_unless($request->user()->can('notes.write'), 403);

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:60'],
            'notes' => ['required', 'array', 'min:1'],
            'notes.*.enrollment_id' => ['required', 'integer'],
            'notes.*.note_category_id' => ['required', 'integer', TenantRule::exists('note_categories')],
            'notes.*.body' => ['nullable', 'string', 'max:4000'],
            'notes.*.is_report_visible' => ['nullable', 'boolean'],
        ]);

        // Only this batch's learners. Permission to write for one class is not permission to write
        // for every class in the academy.
        $inBatch = Enrollment::query()->where('batch_id', $batch->getKey())->pluck('id')->all();
        $outside = array_diff(array_column($validated['notes'], 'enrollment_id'), $inBatch);

        if ($outside !== []) {
            throw ValidationException::withMessages([
                'notes' => 'Some of these learners are not in this batch.',
            ]);
        }

        $result = $this->notes->writeMany($validated['notes'], $validated['period'] ?? null);

        return response()->json([
            'data' => $result + [
                'message' => sprintf(
                    '%d written, %d left blank.',
                    $result['written'],
                    $result['skipped'],
                ),
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('notes.view'), 403);

        return response()->json(['data' => NoteCategory::query()->orderBy('sort')->get()]);
    }
}
