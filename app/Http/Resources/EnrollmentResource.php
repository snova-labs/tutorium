<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Enrollment */
final class EnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'learner_id' => $this->learner_id,
            'batch_id' => $this->batch_id,
            'enrolled_on' => $this->enrolled_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'status' => [
                'id' => $this->status_id,
                'name' => $this->whenLoaded('status', fn () => $this->status->name),
                'reason' => $this->status_reason,
                // Surfaced deliberately: a customer should never have to ask us which of their
                // statuses count toward the bill.
                'counts_toward_billing' => $this->whenLoaded('status', fn () => $this->status->is_active_for_billing),
            ],
            'transferred_to_enrollment_id' => $this->transferred_to_enrollment_id,
            'learner' => new LearnerResource($this->whenLoaded('learner')),
            'batch' => new BatchResource($this->whenLoaded('batch')),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'from' => $h->fromStatus?->name,
                'to' => $h->toStatus?->name,
                'reason' => $h->reason,
                'changed_at_utc' => $h->changed_at?->toIso8601String(),
            ])),
        ];
    }
}
