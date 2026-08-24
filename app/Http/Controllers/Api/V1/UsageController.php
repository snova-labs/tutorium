<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\EnrollmentStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\EntitlementService;
use App\Services\MeteringService;
use App\Support\Billing\Entitlement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a customer sees about their own usage and bill.
 */
final class UsageController
{
    public function __construct(
        private readonly MeteringService $metering,
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * The live meter.
     *
     * Returned with the counting rules alongside the number, because the whole point is that an
     * invoice is never the first time a customer meets the figure (FR-MTR-3).
     */
    public function meter(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage') || $request->user()->can('dashboard.tenant'), 403);

        $tenant = $this->tenancy->require();

        return response()->json([
            'data' => [
                'meter' => $this->metering->liveMeter($tenant),
                // The definition, on the customer's own screen rather than in a contract clause.
                'what_counts' => [
                    'counted' => EnrollmentStatus::query()->billable()->pluck('name'),
                    'not_counted' => EnrollmentStatus::query()
                        ->where('is_active_for_billing', false)->pluck('name'),
                    'rules' => [
                        'A learner in three classes counts once.',
                        'A learner who joins and leaves inside a period counts for that period.',
                        'Archived, completed and withdrawn learners never count. Keeping history is free.',
                        'Sample data is excluded.',
                    ],
                ],
                'plan' => $this->planSummary(),
            ],
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('billing.manage'), 403);

        return response()->json([
            'data' => Invoice::query()
                ->with('snapshot')
                ->latest('period_start')
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'number' => $invoice->number,
                    'period' => [
                        'start' => $invoice->period_start->toDateString(),
                        'end' => $invoice->period_end->toDateString(),
                    ],
                    'quantity' => $invoice->quantity,
                    // Traceable by design: the day the figure came from, on the invoice itself.
                    'basis' => $invoice->quantity_basis_date === null ? null : sprintf(
                        'Peak of %d on %s',
                        $invoice->quantity,
                        $invoice->quantity_basis_date->toDateString(),
                    ),
                    'total' => $invoice->total(),
                    'status' => $invoice->status,
                    'notes' => $invoice->notes,
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function planSummary(): array
    {
        $subscription = Subscription::query()->with('plan')->latest('id')->first();

        return [
            'name' => $subscription?->plan->name,
            'status' => $subscription?->status,
            'source' => $this->entitlements->sourceName(),
            'entitlements' => collect($this->entitlements->all())
                ->map(fn (Entitlement $e) => $e->toArray())
                ->values(),
        ];
    }
}
