<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
final class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'name' => $this->name,
            'code' => $this->code,
            'audience' => $this->audience,
            'description' => $this->description,
            'periods' => [
                'type' => $this->period_type->value,
                'label' => $this->period_type->label(),
                'anchor_month' => $this->period_anchor_month,
                'block_weeks' => $this->period_block_weeks,
                'is_computed' => $this->period_type->isComputed(),
            ],
            'is_active' => $this->is_active,
            'batches_count' => $this->whenCounted('batches'),
            'created_at_utc' => $this->created_at?->toIso8601String(),
        ];
    }
}
