<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Sequences\IdSequenceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning a period of measurements into a bill.
 *
 * Replaces the single-line version from the first billing layer. The reason is the one recorded in
 * the payment migration: with usage-based pricing, a mid-period plan change cannot be handled by
 * prorating a flat fee, because there is no flat fee. The honest treatment is to split the period
 * at the change and meter each part against its own rate — which produces two lines a customer can
 * check separately (SL-BIL-006 §4.2).
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

        $segments = $this->segments($tenant, $subscription, $periodStart, $periodEnd);
        $notes = [];
        $subtotal = 0;
        $lines = [];

        foreach ($segments as $index => $segment) {
            $peak = $this->metering->peakFor($tenant, $segment['from'], $segment['to']);
            $plan = $segment['plan'];
            $amount = $peak['quantity'] * $plan->unit_price_minor;
            $subtotal += $amount;

            $lines[] = [
                'plan_id' => $plan->getKey(),
                'description' => count($segments) === 1
                    ? 'Active learners'
                    : sprintf('Active learners on %s', $plan->name),
                'period_start' => $segment['from']->toDateString(),
                'period_end' => $segment['to']->toDateString(),
                'quantity' => $peak['quantity'],
                'quantity_basis_date' => $peak['snapshot']?->snapshot_date->toDateString(),
                'quantity_snapshot_id' => $peak['snapshot']?->getKey(),
                'unit_price_minor' => $plan->unit_price_minor,
                'amount_minor' => $amount,
                'sort' => $index,
            ];

            $notes = array_merge($notes, $this->segmentNotes($peak, $segment, count($segments) > 1));
        }

        // The headline plan for the invoice is the one in force at the end of the period.
        $finalPlan = end($segments)['plan'];
        $headline = $lines[array_key_last($lines)];

        // A minimum applies once per invoice, not once per segment — otherwise a plan change would
        // charge a small academy the minimum twice for one month.
        $adjustment = max(0, $finalPlan->minimum_charge_minor - $subtotal);
        $total = $subtotal + $adjustment;

        if ($adjustment > 0) {
            $notes[] = sprintf(
                'A minimum charge of %s applies, so %s was added.',
                $this->money($finalPlan->minimum_charge_minor, $finalPlan->currency),
                $this->money($adjustment, $finalPlan->currency),
            );
        }

        $notes[] = 'Archived, completed and withdrawn learners are never counted. Keeping history is free.';

        return $this->tenancy->runAs($tenant, fn () => DB::transaction(function () use (
            $subscription, $periodStart, $periodEnd, $headline, $finalPlan,
            $subtotal, $adjustment, $total, $notes, $lines
        ): Invoice {
            $invoice = Invoice::query()->create([
                'subscription_id' => $subscription->getKey(),
                'number' => $this->sequences->next('invoice'),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'quantity' => $headline['quantity'],
                'quantity_basis_date' => $headline['quantity_basis_date'],
                'quantity_snapshot_id' => $headline['quantity_snapshot_id'],
                'currency' => $finalPlan->currency,
                'unit_price_minor' => $finalPlan->unit_price_minor,
                'subtotal_minor' => $subtotal,
                'minimum_adjustment_minor' => $adjustment,
                // Left at zero until an operating entity and a tax provider are decided
                // (SL-PRD-000 D2). Stating that plainly beats inventing a rate.
                'tax_minor' => 0,
                'total_minor' => $total,
                'tax_note' => 'Tax not applied — pending the operating entity decision.',
                'status' => Invoice::DRAFT,
                'provider' => $subscription->provider,
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                InvoiceLine::query()->create($line + ['invoice_id' => $invoice->getKey()]);
            }

            return $invoice->refresh();
        }));
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
     * Split the period wherever a plan changed inside it.
     *
     * @return array<int, array{from: CarbonImmutable, to: CarbonImmutable, plan: Plan}>
     */
    private function segments(Tenant $tenant, Subscription $subscription, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $changes = $this->tenancy->runAs($tenant, fn () => PlanChange::query()
            ->with(['fromPlan', 'toPlan'])
            ->whereNotNull('applied_at')
            ->whereBetween('effective_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('effective_on')
            ->get());

        if ($changes->isEmpty()) {
            return [['from' => $from, 'to' => $to, 'plan' => $subscription->plan]];
        }

        $segments = [];
        $cursor = $from;

        foreach ($changes as $change) {
            $boundary = CarbonImmutable::parse($change->effective_on->toDateString());

            if ($boundary->greaterThan($cursor)) {
                $segments[] = [
                    'from' => $cursor,
                    'to' => $boundary->subDay(),
                    'plan' => $change->fromPlan ?? $subscription->plan,
                ];
            }

            $cursor = $boundary;
        }

        $segments[] = ['from' => $cursor, 'to' => $to, 'plan' => $changes->last()->toPlan];

        return $segments;
    }

    /**
     * @param array<string, mixed> $peak
     * @param array{from: CarbonImmutable, to: CarbonImmutable, plan: Plan} $segment
     * @return array<int, string>
     */
    private function segmentNotes(array $peak, array $segment, bool $isSplit): array
    {
        $notes = [];

        if ($peak['snapshot'] !== null) {
            $notes[] = $isSplit
                ? sprintf(
                    '%s to %s on %s: highest daily count was %d, on %s.',
                    $segment['from']->toDateString(),
                    $segment['to']->toDateString(),
                    $segment['plan']->name,
                    $peak['quantity'],
                    $peak['snapshot']->snapshot_date->toDateString(),
                )
                : sprintf(
                    'Billed for the highest daily count in the period: %d active learners on %s.',
                    $peak['quantity'],
                    $peak['snapshot']->snapshot_date->toDateString(),
                );
        }

        if ($peak['snapshot']?->is_correction) {
            $notes[] = 'That day was corrected: '.$peak['snapshot']->correction_reason;
        }

        if ($peak['missing_days'] !== []) {
            $notes[] = sprintf(
                '%d day(s) in this period were not measured and are not reflected in the figure.',
                count($peak['missing_days']),
            );
        }

        return $notes;
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }

    private function subscriptionFor(Tenant $tenant): ?Subscription
    {
        return $this->tenancy->runAs(
            $tenant,
            fn () => Subscription::query()->with('plan')->latest('id')->first(),
        );
    }
}
