<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreBrandRequest;
use App\Http\Requests\Api\V1\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
