<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\OnboardingChecklist;
use App\Services\SampleDataService;
use App\Support\Presets\PresetApplier;
use App\Support\Presets\PresetDefinition;
use App\Support\Presets\PresetRepository;
use App\Support\Tenancy\TenantContext;
use App\Support\Terminology\Terminology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OnboardingController
{
    public function __construct(
        private readonly OnboardingChecklist $checklist,
        private readonly PresetRepository $presets,
        private readonly PresetApplier $applier,
        private readonly SampleDataService $sample,
        private readonly Terminology $terms,
        private readonly TenantContext $tenancy,
    ) {}

    /** Where this account has got to, derived from its own data. */
    public function status(Request $request): JsonResponse
    {
        $tenant = $this->tenancy->require();

        return response()->json([
            'data' => $this->checklist->for($tenant) + [
                'sample_data_loaded' => $this->sample->isLoaded($tenant),
                'preset' => $tenant->preset_code,
            ],
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $this->checklist->dismiss($this->tenancy->require());

        return response()->json(['data' => ['message' => 'Setup guide hidden. Reopen it from settings.']]);
    }

    /** Bring the setup guide back after it was hidden. */
    public function reopen(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $this->checklist->reopen($this->tenancy->require());

        return response()->json(['data' => ['message' => 'Setup guide is back on the home page.']]);
    }

    /** The catalogue, with what each preset would set, for the screen that asks someone to choose. */
    public function presets(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->presets->all()
                ->map(fn (PresetDefinition $preset) => $preset->toArray())
                ->values(),
        ]);
    }

    public function applyPreset(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $validated = $request->validate([
            'preset_code' => ['required', 'string', 'max:64'],
        ]);

        $summary = $this->applier->apply($this->tenancy->require(), $validated['preset_code']);
        $this->terms->forget();

        return response()->json([
            'data' => [
                'created' => $summary,
                // Re-applying is safe and says so: anything a customer has already changed is
                // left exactly as they left it.
                'message' => sprintf(
                    '%d settings and vocabulary entries added. Anything you had already changed was left alone.',
                    array_sum($summary),
                ),
            ],
        ]);
    }

    public function loadSample(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $set = $this->sample->load($this->tenancy->require());

        return response()->json([
            'data' => [
                'records' => count($set->created),
                'message' => 'Sample class loaded. Remove it in one click when you no longer need it.',
            ],
        ], 201);
    }

    public function removeSample(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $removed = $this->sample->remove($this->tenancy->require());

        return response()->json([
            'data' => ['removed' => $removed, 'message' => "Removed {$removed} sample records."],
        ]);
    }
}
