<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * Replace the brand's logo, discarding the one it had.
     *
     * Stored under the tenant with a random name: the file is served through an authenticated
     * endpoint, and a guessable path would still be worth guessing if the disk ever became public.
     */
    public function setLogo(Brand $brand, UploadedFile $logo): Brand
    {
        $previous = $brand->logo_path;

        $path = $logo->store(sprintf('branding/tenant-%d', $brand->tenant_id), $this->logoDisk());

        if ($path === false) {
            throw ValidationException::withMessages(['logo' => 'The image could not be saved. Try again.']);
        }

        $brand->update(['logo_path' => $path]);

        $this->forget($previous);

        return $brand->refresh();
    }

    public function clearLogo(Brand $brand): Brand
    {
        $previous = $brand->logo_path;

        $brand->update(['logo_path' => null]);

        $this->forget($previous);

        return $brand->refresh();
    }

    /**
     * The logo as a data URI, for a renderer that cannot reach the disk — the PDF engine reads
     * HTML with no credentials, so an authenticated URL would come back as the sign-in page.
     */
    public function logoDataUri(Brand $brand): ?string
    {
        if ($brand->logo_path === null) {
            return null;
        }

        $disk = Storage::disk($this->logoDisk());

        if (! $disk->exists($brand->logo_path)) {
            return null;
        }

        $contents = $disk->get($brand->logo_path);

        if ($contents === null) {
            return null;
        }

        return 'data:'.($disk->mimeType($brand->logo_path) ?: 'image/png').';base64,'.base64_encode($contents);
    }

    public function logoDisk(): string
    {
        return (string) config('branding.logo.disk');
    }

    /** Deleting the old file is housekeeping: a failure here must not fail the request. */
    private function forget(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk($this->logoDisk())->delete($path);
        }
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
