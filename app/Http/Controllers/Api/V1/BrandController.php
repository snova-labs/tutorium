<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreBrandLogoRequest;
use App\Http\Requests\Api\V1\StoreBrandRequest;
use App\Http\Requests\Api\V1\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandController
{
    public function __construct(private readonly BrandService $brands) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->user()->can('viewAny', Brand::class) ?: abort(403);

        // No tenant filter here, and none anywhere else: the global scope has already applied it.
        $brands = Brand::query()
            ->withCount('branches')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(25);

        return BrandResource::collection($brands);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->brands->create($request->validated());

        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    public function show(Request $request, Brand $brand): BrandResource
    {
        $this->authorizeOr403($request, 'view', $brand);

        return new BrandResource($brand->loadCount('branches'));
    }

    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        return new BrandResource($this->brands->update($brand, $request->validated()));
    }

    /**
     * The logo itself, for an <img> in the client.
     *
     * Served rather than linked: the file sits on a private disk, so this endpoint is what applies
     * the account's own permissions to it.
     */
    public function logo(Request $request, Brand $brand): StreamedResponse
    {
        $this->authorizeOr403($request, 'view', $brand);

        $disk = Storage::disk($this->brands->logoDisk());

        abort_if($brand->logo_path === null || ! $disk->exists($brand->logo_path), 404);

        return $disk->response($brand->logo_path, null, [
            // Private: a shared cache must not hand one academy's logo to another's browser.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function storeLogo(StoreBrandLogoRequest $request, Brand $brand): BrandResource
    {
        return new BrandResource($this->brands->setLogo($brand, $request->file('logo')));
    }

    public function destroyLogo(Request $request, Brand $brand): BrandResource
    {
        $this->authorizeOr403($request, 'update', $brand);

        return new BrandResource($this->brands->clearLogo($brand));
    }

    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        $this->authorizeOr403($request, 'delete', $brand);

        $this->brands->archive($brand);

        return response()->json([
            'data' => ['message' => 'Brand archived. Its branches are now inactive and every record is kept.'],
        ]);
    }

    private function authorizeOr403(Request $request, string $ability, Brand $brand): void
    {
        abort_unless($request->user()->can($ability, $brand), 403);
    }
}
