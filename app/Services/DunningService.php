<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingProfile;
use App\Models\DunningAttempt;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Payments\PaymentProvider;
use App\Support\Payments\PaymentResult;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collecting an unpaid invoice, and what happens when we cannot.
 *
 * The escalation is published in advance and applied identically to everyone: four attempts over a
 * week, then a banner, then read-only. Read-only means writing stops — reading, downloading and
 * exporting never do. An academy that cannot pay this month still has parents expecting reports
 * and inspectors expecting records, and withholding those would be punishing the wrong people
 * (SL-BIL-006 §6).
 */
final class DunningService
{
    /** Days after the first failure on which we try again. */
    private const SCHEDULE = [1, 3, 5, 7];

    public function __construct(
        private readonly PaymentProvider $payments,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Attempt collection on one invoice.
     */
    public function attempt(Tenant $tenant, Invoice $invoice): DunningAttempt
    {
        return $this->tenancy->runAs($tenant, function () use ($tenant, $invoice): DunningAttempt {
            $profile = BillingProfile::query()->firstOrCreate([], ['provider' => $this->payments->name()]);
            $attemptNumber = DunningAttempt::query()->where('invoice_id', $invoice->getKey())->count() + 1;

            // Customers who pay by transfer are never chased by card. Chasing them would be both
            // futile and insulting.
            if ($profile->prefers_invoicing) {
                return $this->record($invoice, $attemptNumber, DunningAttempt::SKIPPED, null, 'Pays by bank transfer.');
            }

            $result = $this->payments->charge($invoice, $profile);

            return $result->succeeded
                ? $this->onSuccess($tenant, $invoice, $attemptNumber, $result)
                : $this->onFailure($tenant, $invoice, $attemptNumber, $result);
        });
    }

    /**
     * Work through everything due for another attempt.
     *
     * @return array{attempted: int, recovered: int, suspended: int}
     */
    public function run(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $summary = ['attempted' => 0, 'recovered' => 0, 'suspended' => 0];

        $tenants = $this->tenancy->withoutScoping(
            fn () => Tenant::query()->whereIn('status', [
                Tenant::STATUS_ACTIVE, Tenant::STATUS_PAST_DUE, Tenant::STATUS_SUSPENDED,
            ])->get(),
        );

        foreach ($tenants as $tenant) {
            foreach ($this->dueInvoices($tenant, $at) as $invoice) {
                $before = $tenant->refresh()->status;
                $attempt = $this->attempt($tenant, $invoice);

                $summary['attempted']++;

                if ($attempt->outcome === DunningAttempt::SUCCEEDED && $before !== Tenant::STATUS_ACTIVE) {
                    $summary['recovered']++;
                }

                if ($tenant->refresh()->status === Tenant::STATUS_SUSPENDED && $before !== Tenant::STATUS_SUSPENDED) {
                    $summary['suspended']++;
                }
            }
        }

        return $summary;
    }

    /**
     * What a customer is told, before anything happens to them.
     *
     * @return array<string, mixed>
     */
    public function scheduleFor(Tenant $tenant, Invoice $invoice): array
    {
        return $this->tenancy->runAs($tenant, function () use ($invoice): array {
            $attempts = DunningAttempt::query()
                ->where('invoice_id', $invoice->getKey())
                ->orderBy('attempt')
                ->get();

            $last = $attempts->last();

            return [
                'attempts_made' => $attempts->count(),
                'attempts_remaining' => max(0, count(self::SCHEDULE) - $attempts->where('outcome', DunningAttempt::FAILED)->count()),
                'next_attempt' => $last?->next_attempt_at?->toDateString(),
                'what_happens_next' => [
                    'We try again on days '.implode(', ', self::SCHEDULE).' after the first failure, and email you each time.',
                    'Your account keeps working throughout, with a notice on screen.',
                    'After the final attempt the account becomes read-only.',
                    'Read-only means you can still read, download and export everything. Only entering new data stops.',
                ],
                'history' => $attempts->map(fn (DunningAttempt $a) => [
                    'attempt' => $a->attempt,
                    'at' => $a->attempted_at->toDateString(),
                    'outcome' => $a->outcome,
                    'reason' => $a->failure_message,
                ]),
            ];
        });
    }

    private function onSuccess(Tenant $tenant, Invoice $invoice, int $attempt, PaymentResult $result): DunningAttempt
    {
        $invoice->update([
            'status' => Invoice::PAID,
            'paid_at' => now(),
            'provider_ref' => $result->reference,
        ]);

        Subscription::query()->latest('id')->first()?->update(['status' => Subscription::ACTIVE]);

        // Recovery is instant and complete, because suspension never removed anything.
        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            $this->setTenantStatus($tenant, Tenant::STATUS_ACTIVE, 'Payment received.');
        }

        return $this->record($invoice, $attempt, DunningAttempt::SUCCEEDED, $result->reference);
    }

    private function onFailure(Tenant $tenant, Invoice $invoice, int $attempt, PaymentResult $result): DunningAttempt
    {
        $failedSoFar = DunningAttempt::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('outcome', DunningAttempt::FAILED)
            ->count() + 1;

        $exhausted = $failedSoFar >= count(self::SCHEDULE) || ! $result->isWorthRetrying();

        $next = $exhausted
            ? null
            : CarbonImmutable::now()->addDays(self::SCHEDULE[$failedSoFar] - self::SCHEDULE[$failedSoFar - 1]);

        Subscription::query()->latest('id')->first()?->update(['status' => Subscription::PAST_DUE]);

        if ($exhausted) {
            $this->setTenantStatus(
                $tenant,
                Tenant::STATUS_SUSPENDED,
                'Collection attempts exhausted: '.$result->customerMessage(),
            );
        } elseif ($tenant->status === Tenant::STATUS_ACTIVE) {
            // Past due, and still fully working. The banner does the work, not a lockout.
            $this->setTenantStatus($tenant, Tenant::STATUS_PAST_DUE, $result->customerMessage());
        }

        return $this->record(
            $invoice,
            $attempt,
            DunningAttempt::FAILED,
            null,
            $result->customerMessage(),
            $result->failureCode,
            $next,
        );
    }

    private function record(
        Invoice $invoice,
        int $attempt,
        string $outcome,
        ?string $reference = null,
        ?string $message = null,
        ?string $code = null,
        ?CarbonImmutable $next = null,
    ): DunningAttempt {
        return DunningAttempt::query()->create([
            'invoice_id' => $invoice->getKey(),
            'attempt' => $attempt,
            'attempted_at' => now(),
            'outcome' => $outcome,
            'provider' => $this->payments->name(),
            'failure_code' => $code,
            'failure_message' => $message,
            'next_attempt_at' => $next,
        ]);
    }

    /**
     * Move the account between billing states, with the reason in the customer's own activity log.
     *
     * Public so a payment that arrives by webhook restores an account the same way a successful
     * retry does.
     */
    public function setTenantStatus(Tenant $tenant, string $status, string $reason): void
    {
        $from = $tenant->status;

        DB::transaction(function () use ($tenant, $status, $reason, $from): void {
            $this->tenancy->withoutScoping(function () use ($tenant, $status, $reason, $from): void {
                $tenant->update([
                    'status' => $status,
                    'suspended_at' => $status === Tenant::STATUS_SUSPENDED ? now() : null,
                ]);

                // In the customer's own activity log, so an account that goes read-only can see
                // exactly why without contacting us.
                AuditLog::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'actor_type' => AuditLog::ACTOR_SYSTEM,
                    'actor_name' => 'Billing',
                    'module' => 'Account',
                    'action' => 'status_changed',
                    'target_label' => $tenant->name,
                    'before' => ['status' => $from],
                    'after' => ['status' => $status, 'reason' => $reason],
                    'occurred_at' => now(),
                ]);
            });
        });
    }

    /** @return Collection<int, Invoice> */
    private function dueInvoices(Tenant $tenant, CarbonImmutable $at): Collection
    {
        return $this->tenancy->runAs($tenant, function () use ($at) {
            return Invoice::query()
                ->where('status', Invoice::ISSUED)
                ->get()
                ->filter(function (Invoice $invoice) use ($at): bool {
                    $last = DunningAttempt::query()
                        ->where('invoice_id', $invoice->getKey())
                        ->orderByDesc('attempt')
                        ->first();

                    if ($last === null) {
                        return true;
                    }

                    // Nothing further scheduled means the schedule is exhausted; leave it alone.
                    return $last->outcome === DunningAttempt::FAILED
                        && $last->next_attempt_at !== null
                        && $last->next_attempt_at <= $at;
                })
                ->values();
        });
    }
}
