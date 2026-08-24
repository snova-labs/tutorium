<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Batch */
final class BatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'code' => $this->code,

            // Named explicitly rather than left implicit: a client rendering a schedule must know
            // which clock the times below belong to.
            'timezone' => $this->timezone,
            'timezone_source' => $this->relationLoaded('branch') && $this->branch?->timezone === $this->timezone
                ? 'inherited from branch'
                : 'set on this batch',

            'runs' => [
                'starts_on' => $this->starts_on?->toDateString(),
                'ends_on' => $this->ends_on?->toDateString(),
            ],
            'capacity' => $this->capacity,
            'delivery_mode' => $this->delivery_mode->value,
            'status' => $this->status->value,
            'accepts_enrollments' => $this->status->acceptsEnrollments(),

            'course' => new CourseResource($this->whenLoaded('course')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'teachers' => UserResource::collection($this->whenLoaded('teachers')),
            'timetable' => TimetableSlotResource::collection($this->whenLoaded('timetableSlots')),
            'sessions_count' => $this->whenCounted('sessions'),
        ];
    }
}
