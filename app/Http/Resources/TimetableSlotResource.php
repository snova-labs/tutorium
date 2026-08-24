<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimetableSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimetableSlot */
final class TimetableSlotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'session_type' => [
                'id' => $this->session_type_id,
                'name' => $this->whenLoaded('sessionType', fn () => $this->sessionType->name),
            ],
            'weekday' => [
                'iso' => $this->weekday->value,
                'name' => $this->weekday->label(),
            ],
            'start_time_local' => $this->localTime(),
            'duration_min' => $this->duration_min,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
