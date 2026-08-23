<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Branch */
final class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'locale_rules' => [
                'timezone' => $this->timezone,
                'week_start' => $this->week_start,
                'weekend_days' => $this->weekend_days,
            ],
            'contact' => [
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
            ],
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'created_at_utc' => $this->created_at?->toIso8601String(),
        ];
    }
}
