<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CourseController
{
    public function __construct(private readonly CourseService $courses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Course::class), 403);

        $query = Course::query()->withCount('batches')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return CourseResource::collection($query->paginate(25));
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $course = $this->courses->create($request->validated());

        return (new CourseResource($course))->response()->setStatusCode(201);
    }

    public function show(Request $request, Course $course): CourseResource
    {
        abort_unless($request->user()->can('view', $course), 403);

        return new CourseResource($course->loadCount('batches'));
    }

    public function update(Request $request, Course $course): CourseResource
    {
        abort_unless($request->user()->can('update', $course), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience' => ['sometimes', 'string', 'max:32'],
            'period_type' => ['sometimes', 'string'],
            'period_anchor_month' => ['nullable', 'integer', 'between:1,12'],
            'period_block_weeks' => ['nullable', 'integer', 'between:1,52'],
            'is_active' => ['boolean'],
        ]);

        return new CourseResource($this->courses->update($course, $validated));
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        abort_unless($request->user()->can('delete', $course), 403);

        $this->courses->archive($course);

        return response()->json([
            'data' => ['message' => 'Course archived. Its history is kept and its batches are unaffected.'],
        ]);
    }
}
