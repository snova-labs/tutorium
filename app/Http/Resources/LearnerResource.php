<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Learner */
final class LearnerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => [
                'legal' => $this->legal_name,
                'preferred' => $this->preferred_name,
                'display' => $this->displayName(),
            ],
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'country' => $this->country,
            'home_timezone' => $this->home_timezone,
            'contact' => [
                'email' => $this->email,
                'phone' => $this->phone,
            ],
            'status' => [
                'id' => $this->status_id,
                'name' => $this->whenLoaded('status', fn () => $this->status->name),
                'reason' => $this->status_reason,
                'changed_on' => $this->status_changed_on?->toDateString(),
            ],
            'custom' => $this->custom,
            'guardians' => GuardianResource::collection($this->whenLoaded('guardians')),
            'enrollments' => EnrollmentResource::collection($this->whenLoaded('enrollments')),
            'created_at_utc' => $this->created_at?->toIso8601String(),
        ];
    }
}
