<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreBranchRequest;
use App\Http\Requests\Api\V1\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BranchController
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Branch::class), 403);

        $query = Branch::query()->with('brand')->orderBy('name');

        // Branch scope is a filter for readers and a hard check for writers. Both, always.
        if (! $request->user()->scope_all_branches) {
            $query->whereIn('id', $request->user()->branches()->pluck('branches.id'));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return BranchResource::collection($query->paginate(25));
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        // Resolved through the scoped query, so a brand id belonging to another account comes
        // back as not-found rather than forbidden — which would confirm it exists.
        $brand = Brand::query()->findOrFail($request->integer('brand_id'));

        $branch = $this->branches->create($brand, $request->validated());

        return (new BranchResource($branch->load('brand')))->response()->setStatusCode(201);
    }

    public function show(Request $request, Branch $branch): BranchResource
    {
        abort_unless($request->user()->can('view', $branch), 403);

        return new BranchResource($branch->load('brand'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->update($branch, $request->validated())->load('brand'));
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->can('delete', $branch), 403);

        $this->branches->archive($branch);

        return response()->json([
            'data' => ['message' => 'Branch archived. New enrollments are blocked and every record is kept.'],
        ]);
    }
}
