<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Sequences\IdSequenceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning a month of measurements into a bill.
 *
 * Every figure on the result can be traced: the quantity points at the snapshot it came from, the
 * date that snapshot was taken is printed, and any correction that fed into it is named in the
 * notes. A customer should be able to check an invoice against their own roster without asking us
 * anything (FR-BIL-4).
 */
final class InvoiceComposer
{
    public function __construct(
        private readonly MeteringService $metering,
        private readonly IdSequenceService $sequences,
        private readonly TenantContext $tenancy,
    ) {}

    public function draft(Tenant $tenant, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Invoice
    {
        $subscription = $this->subscriptionFor($tenant);

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'subscription' => 'This account has no subscription to invoice.',
            ]);
        }

        $peak = $this->metering->peakFor($tenant, $periodStart, $periodEnd);
        $plan = $subscription->plan;

        $subtotal = $peak['quantity'] * $plan->unit_price_minor;

        // A minimum charge is stated as its own line rather than folded into the unit price, so a
        // customer with three learners can see why they were charged for more.
        $adjustment = max(0, $plan->minimum_charge_minor - $subtotal);
        $total = $subtotal + $adjustment;

        $notes = $this->notes($peak, $plan, $adjustment);

        return $this->tenancy->runAs($tenant, fn () => DB::transaction(fn () => Invoice::query()->create([
            'subscription_id' => $subscription->getKey(),
            'number' => $this->sequences->next('invoice'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'quantity' => $peak['quantity'],
            'quantity_basis_date' => $peak['snapshot']?->snapshot_date->toDateString(),
            'quantity_snapshot_id' => $peak['snapshot']?->getKey(),
            'currency' => $plan->currency,
            'unit_price_minor' => $plan->unit_price_minor,
            'subtotal_minor' => $subtotal,
            'minimum_adjustment_minor' => $adjustment,
            // Tax is left at zero until an operating entity and a tax provider are decided
            // (SL-PRD-000 D2). Stating that plainly beats inventing a rate.
            'tax_minor' => 0,
            'total_minor' => $total,
            'tax_note' => 'Tax not applied — pending the operating entity decision.',
            'status' => Invoice::DRAFT,
            'provider' => $subscription->provider,
            'notes' => $notes,
        ])));
    }

    public function issue(Invoice $invoice): Invoice
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has already been issued. Raise a credit note instead of editing it.',
            ]);
        }

        $invoice->update(['status' => Invoice::ISSUED, 'issued_at' => now()]);

        return $invoice->refresh();
    }

    public function markPaid(Invoice $invoice, ?string $reference = null): Invoice
    {
        $invoice->update([
            'status' => Invoice::PAID,
            'paid_at' => now(),
            'provider_ref' => $reference ?? $invoice->provider_ref,
        ]);

        return $invoice->refresh();
    }

    /**
     * @param array<string, mixed> $peak
     * @return array<int, string>
     */
    private function notes(array $peak, $plan, int $adjustment): array
    {
        $notes = [];

        if ($peak['snapshot'] !== null) {
            $notes[] = sprintf(
                'Billed for the highest daily count in the period: %d active learners on %s.',
                $peak['quantity'],
                $peak['snapshot']->snapshot_date->toDateString(),
            );
        }

        if ($peak['snapshot']?->is_correction) {
            // Corrections appear on the invoice rather than only in our own records.
            $notes[] = 'That day was corrected: '.$peak['snapshot']->correction_reason;
        }

        if ($peak['missing_days'] !== []) {
            $notes[] = sprintf(
                '%d day(s) in this period were not measured and are not reflected in the figure.',
                count($peak['missing_days']),
            );
        }

        if ($adjustment > 0) {
            $notes[] = sprintf(
                'A minimum charge of %s applies, so %s was added.',
                number_format($plan->minimum_charge_minor / 100, 2).' '.$plan->currency,
                number_format($adjustment / 100, 2).' '.$plan->currency,
            );
        }

        $notes[] = 'Archived, completed and withdrawn learners are never counted. Keeping history is free.';

        return $notes;
    }

    private function subscriptionFor(Tenant $tenant): ?Subscription
    {
        return $this->tenancy->runAs(
            $tenant,
            fn () => Subscription::query()->with('plan')->latest('id')->first(),
        );
    }
}
