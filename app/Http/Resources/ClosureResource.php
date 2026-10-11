<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Holiday */
final class ClosureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            // A local date, with no time and no zone: a branch is closed for its own day.
            'date' => $this->date->toDateString(),
            'name' => $this->name,
            'blocks_sessions' => $this->blocks_sessions,
        ];
    }
}
