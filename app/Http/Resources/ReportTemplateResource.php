<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportTemplate */
final class ReportTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'blocks' => $this->blocks ?? [],
            'closing' => $this->closing,
            'locale' => $this->locale,
            'is_default' => $this->is_default,
            // What decides whether this template answers for a report: the narrowest match wins,
            // course before brand before the account default.
            'applies_to' => [
                'brand_id' => $this->brand_id,
                'brand' => $this->whenLoaded('brand', fn () => $this->brand?->name),
                'course_id' => $this->course_id,
                'course' => $this->whenLoaded('course', fn () => $this->course?->name),
            ],
        ];
    }
}
