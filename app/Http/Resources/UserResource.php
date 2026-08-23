<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'roles' => $this->getRoleNames(),
            // The client needs the permission list to decide what to show. It is a convenience,
            // never the control — every action re-authorizes on the server.
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'scope' => [
                'all_branches' => $this->scope_all_branches,
                'branch_ids' => $this->scope_all_branches ? null : $this->branches()->pluck('branches.id'),
            ],
            'tenant' => [
                'id' => $this->tenant?->id,
                'name' => $this->tenant?->name,
                'status' => $this->tenant?->status,
            ],
        ];
    }
}
