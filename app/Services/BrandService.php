<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brand rules that outlive any particular screen or endpoint.
 */
final class BrandService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Brand
    {
        return DB::transaction(function () use ($attributes): Brand {
            $brand = Brand::query()->create($attributes);

            // The first brand of an account is its default, without anyone having to decide.
            if (Brand::query()->count() === 1) {
                $brand->forceFill(['is_default' => true])->save();
            } elseif ($brand->is_default) {
                $this->demoteOtherDefaults($brand);
            }

            return $brand->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Brand $brand, array $attributes): Brand
    {
        return DB::transaction(function () use ($brand, $attributes): Brand {
            $brand->update($attributes);

            if ($brand->is_default) {
                $this->demoteOtherDefaults($brand);
            }

            return $brand->refresh();
        });
    }

    /**
     * Archive rather than delete once a brand holds branches. Academic history is never removed
     * by a user action (SL-DAT-003 §13).
     */
    public function archive(Brand $brand): void
    {
        if ($brand->is_default && Brand::query()->where('is_default', true)->count() === 1) {
            throw ValidationException::withMessages([
                'brand' => 'This is your only brand. Create another before archiving this one.',
            ]);
        }

        DB::transaction(function () use ($brand): void {
            $brand->branches()->update(['is_active' => false]);
            $brand->delete();
        });
    }

    private function demoteOtherDefaults(Brand $brand): void
    {
        Brand::query()
            ->whereKeyNot($brand->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
