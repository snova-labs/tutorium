<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClassSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassSession */
final class ClassSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewerZone = $request->user()?->timezone;

        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'session_type' => [
                'id' => $this->session_type_id,
                'name' => $this->whenLoaded('sessionType', fn () => $this->sessionType->name),
                'counts_in_attendance' => $this->whenLoaded('sessionType', fn () => $this->sessionType->counts_in_attendance),
            ],

            // Three representations, deliberately. The local pair is what was agreed and must
            // never drift; the UTC instant is what everything computes on; the viewer pair is a
            // courtesy for a coordinator in another country (SL-LOC-005 §2).
            'local' => [
                'date' => $this->session_local_date->toDateString(),
                'time' => substr((string) $this->start_time_local, 0, 5),
                'timezone' => $this->whenLoaded('batch', fn () => $this->batch->timezone),
            ],
            'utc' => [
                'starts_at' => $this->starts_at_utc->toIso8601String(),
                'ends_at' => $this->ends_at_utc->toIso8601String(),
            ],
            'viewer' => $viewerZone === null ? null : [
                'starts_at' => $this->startsAtIn($viewerZone)->toIso8601String(),
                'timezone' => $viewerZone,
            ],

            'status' => $this->status->value,
            'counts_toward_attendance' => $this->status->countsTowardAttendance(),
            'cancel_reason' => $this->cancel_reason,
            'meeting_url' => $this->meeting_url,
            'is_generated' => $this->generated_from_slot_id !== null,
        ];
    }
}
