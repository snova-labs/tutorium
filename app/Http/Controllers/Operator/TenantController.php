<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Jobs\BuildTenantExportJob;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Services\ImpersonationService;
use App\Services\TenantExportService;
use App\Services\TenantHealthService;
use App\Services\TenantLifecycleService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantController
{
    public function __construct(
        private readonly TenantHealthService $health,
        private readonly TenantLifecycleService $lifecycle,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantExportService $exports,
        private readonly TenantContext $tenancy,
    ) {}

    /** The directory, ordered so that whatever needs attention is at the top. */
    public function index(Request $request): JsonResponse
    {
        $tenants = $this->tenancy->withoutScoping(
            fn () => Tenant::query()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
                ->orderBy('name')
                ->get(),
        );

        $rows = $tenants->map(fn (Tenant $tenant) => [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'preset' => $tenant->preset_code,
            'region' => $tenant->region_code,
            'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
            'health' => $this->health->for($tenant)->toArray(),
        ]);

        return response()->json([
            'data' => [
                'tenants' => $rows->sortByDesc('health.needs_attention')->values(),
                'needing_attention' => $rows->where('health.needs_attention', true)->count(),
                'total' => $rows->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
            'password' => ['nullable', 'string', 'min:12'],
            'timezone' => ['required', 'timezone:all'],
            'week_start' => ['nullable', 'string'],
            'weekend_days' => ['nullable', 'array'],
            'preset_code' => ['required', 'string', 'max:64'],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'branch_name' => ['nullable', 'string', 'max:160'],
            'trial_days' => ['nullable', 'integer', 'between:1,90'],
            'region_code' => ['nullable', 'string', 'max:16'],
        ]);

        $result = $this->provisioner->provision($validated);

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $result['tenant']->getKey(),
                    'name' => $result['tenant']->name,
                    'slug' => $result['tenant']->slug,
                    'trial_ends_at' => $result['tenant']->trial_ends_at?->toDateString(),
                ],
                'owner_email' => $result['owner']->email,
                'preset_created' => $result['preset'],
            ],
        ], 201);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'health' => $this->health->for($tenant)->toArray(),
                'support_access' => app(ImpersonationService::class)->historyFor($tenant),
                'exports' => $this->tenancy->runAs($tenant, fn (): array => $this->recentExports()),
            ],
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->lifecycle->suspend($tenant, $request->user('operator'), $validated['reason']);

        return response()->json([
            'data' => ['message' => 'Account is read-only. They can still read and export everything.'],
        ]);
    }

    public function reactivate(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->lifecycle->reactivate($tenant, $request->user('operator'), $validated['reason'] ?? 'Reactivated');

        return response()->json(['data' => ['message' => 'Full access restored. Nothing was lost.']]);
    }

    public function cancel(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $updated = $this->lifecycle->cancel($tenant, $request->user('operator'), $validated['reason']);

        return response()->json([
            'data' => [
                'purge_after' => $updated->purge_after?->toDateString(),
                'message' => 'Cancelled. Their data is kept until '
                    .$updated->purge_after?->toDateString().' in case they come back.',
            ],
        ]);
    }

    public function purge(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate(['confirmation' => ['required', 'string']]);

        $hasExport = $this->tenancy->runAs(
            $tenant,
            fn () => TenantExport::query()->where('status', TenantExport::STATUS_READY)->exists(),
        );

        $this->lifecycle->purge($tenant, $request->user('operator'), $validated['confirmation'], $hasExport);

        return response()->json(['data' => ['message' => 'Account purged. This cannot be undone.']]);
    }

    public function export(Request $request, Tenant $tenant): JsonResponse
    {
        $export = $this->exports->request($tenant, operator: $request->user('operator'));

        BuildTenantExportJob::dispatch($export->getKey());

        return response()->json([
            'data' => ['export_id' => $export->getKey(), 'message' => 'Building the export. This may take a few minutes.'],
        ], 202);
    }

    /** @return array<int, array<string, mixed>> */
    private function recentExports(): array
    {
        return TenantExport::query()
            ->latest('id')->take(5)->get()
            ->map(fn (TenantExport $e) => [
                'id' => $e->getKey(),
                'status' => $e->status,
                'size_bytes' => $e->size_bytes,
                'expires_at_utc' => $e->expires_at?->toIso8601String(),
            ])
            ->all();
    }
}
