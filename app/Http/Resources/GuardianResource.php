<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Guardian */
final class GuardianResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'relation' => $this->whenLoaded('relationType', fn () => $this->relationType?->name),
            'contact' => [
                'email' => $this->email,
                'secondary_email' => $this->secondary_email,
                'phone' => $this->phone,
                'preferred_channel' => $this->preferred_channel,
            ],
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            // Present only when read through a learner, because these describe the link rather
            // than the person.
            'link' => $this->whenPivotLoaded('guardian_learner', fn () => [
                // The pivot is a relation set by the learner's guardians(), not an attribute.
                'is_primary' => (bool) $this->resource->getRelation('pivot')->is_primary,
                'receives_reports' => (bool) $this->resource->getRelation('pivot')->receives_reports,
            ]),
            'children_count' => $this->whenCounted('learners'),
        ];
    }
}
