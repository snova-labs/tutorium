<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Branch rules, including the one that matters most: a branch is the origin of local time.
 */
final class BranchService
{
    /** @param array<string, mixed> $attributes */
    public function create(Brand $brand, array $attributes): Branch
    {
        return DB::transaction(function () use ($brand, $attributes): Branch {
            $branch = new Branch($attributes);
            $branch->brand()->associate($brand);

            // Left unset, a branch follows its brand's locale conventions rather than silently
            // defaulting to UTC and quietly mis-scheduling every session under it.
            $branch->timezone = $attributes['timezone'] ?? $this->defaultTimezone($brand);
            $branch->week_start = $attributes['week_start'] ?? 'monday';
            $branch->weekend_days = $attributes['weekend_days'] ?? ['saturday', 'sunday'];

            $branch->save();

            return $branch->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Branch $branch, array $attributes): Branch
    {
        // Changing a timezone reinterprets every future session under this branch, so it is a
        // deliberate act with a warning attached rather than a quiet field edit.
        if (isset($attributes['timezone']) && $attributes['timezone'] !== $branch->timezone) {
            $attributes['timezone_changed_warning'] = true;
        }

        unset($attributes['timezone_changed_warning']);

        return DB::transaction(function () use ($branch, $attributes): Branch {
            $branch->update($attributes);

            return $branch->refresh();
        });
    }

    public function archive(Branch $branch): void
    {
        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'This branch is already archived.',
            ]);
        }

        DB::transaction(function () use ($branch): void {
            $branch->update(['is_active' => false]);
        });
    }

    public function reactivate(Branch $branch): void
    {
        $branch->update(['is_active' => true]);
    }

    private function defaultTimezone(Brand $brand): string
    {
        return $brand->branches()->value('timezone') ?? config('app.timezone', 'UTC');
    }
}
