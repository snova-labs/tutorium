<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportingPeriod */
final class ReportingPeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'course_id' => $this->course_id,
            'type' => $this->type->value,
            'label' => $this->label,
            'starts_local_date' => $this->starts_local_date->toDateString(),
            'ends_local_date' => $this->ends_local_date->toDateString(),
            'status' => $this->status,
            'accepts_routine_edits' => $this->acceptsRoutineEdits(),
            'closed_at_utc' => $this->closed_at?->toIso8601String(),
        ];
    }
}
