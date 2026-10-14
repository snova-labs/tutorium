<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreReportTemplateRequest;
use App\Http\Requests\Api\V1\UpdateReportTemplateRequest;
use App\Http\Resources\ReportTemplateResource;
use App\Models\ReportTemplate;
use App\Services\ReportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What a report contains.
 *
 * There is no destroy: a sent report points at the template it was rendered from, and removing
 * one would change what a re-download of that report looks like. A template that is no longer
 * wanted is narrowed or renamed instead.
 */
final class ReportTemplateController
{
    public function __construct(private readonly ReportTemplateService $templates) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', ReportTemplate::class), 403);

        $templates = ReportTemplate::query()
            ->with(['brand', 'course'])
            // Narrowest first, which is the order they are resolved in.
            ->orderByRaw('course_id is null, brand_id is null')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return ReportTemplateResource::collection($templates)->additional([
            'meta' => ['blocks' => (array) config('reporting.blocks')],
        ]);
    }

    public function store(StoreReportTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->validated());

        return (new ReportTemplateResource($template->load(['brand', 'course'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateReportTemplateRequest $request, ReportTemplate $template): ReportTemplateResource
    {
        $saved = $this->templates->update($template, $request->validated());

        return new ReportTemplateResource($saved->load(['brand', 'course']));
    }
}
