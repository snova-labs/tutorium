<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Brand */
final class BrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_default' => $this->is_default,
            'locale' => $this->locale,
            'colors' => $this->colors,
            'sender' => [
                'name' => $this->sender_name,
                'email' => $this->sender_email,
            ],
            'branches_count' => $this->whenCounted('branches'),
            // Timestamps are UTC and named as such. Local representations belong to the client,
            // which knows the viewer's timezone (SL-OPS-007 §6).
            'created_at_utc' => $this->created_at?->toIso8601String(),
            'updated_at_utc' => $this->updated_at?->toIso8601String(),
        ];
    }
}
