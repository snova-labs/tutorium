<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Enums\PeriodType;
use App\Models\Batch;
use App\Models\ReportingPeriod;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The only place in the application that computes a reporting period boundary.
 *
 * Concentrating this is what prevents the classic defect where a report misses the last session
 * of the month: three files disagree about whether "August" means August in UTC, in the branch's
 * timezone, or in the viewer's. Here it means August in the batch's timezone, always
 * (SL-ARC-002 §12, SL-LOC-005 §5.1).
 */
final class PeriodService
{
    /** The period containing a given moment, or now. */
    public function current(Batch $batch, ?CarbonImmutable $at = null): PeriodBoundary
    {
        $local = ($at ?? CarbonImmutable::now())->setTimezone($batch->timezone);

        return $this->forDate($batch, $local);
    }

    /** The period containing a given local date. */
    public function forDate(Batch $batch, CarbonImmutable $localDate): PeriodBoundary
    {
        $type = $batch->periodType();

        return match ($type) {
            PeriodType::Monthly => $this->monthly($batch, $localDate),
            PeriodType::Quarter => $this->quarter($batch, $localDate),
            PeriodType::Block => $this->block($batch, $localDate),
            PeriodType::Term, PeriodType::Custom => $this->defined($batch, $localDate),
        };
    }

    /** The period a stored label refers to, e.g. "2026-08", "Autumn 2026", "Block 3". */
    public function forLabel(Batch $batch, string $label): PeriodBoundary
    {
        $type = $batch->periodType();

        if ($type === PeriodType::Monthly) {
            $anchor = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $label.'-01 00:00:00', $batch->timezone);

            if ($anchor === null) {
                throw new RuntimeException("[{$label}] is not a valid monthly period label.");
            }

            return $this->monthly($batch, $anchor);
        }

        $row = ReportingPeriod::query()
            ->where('batch_id', $batch->getKey())
            ->where('label', $label)
            ->first();

        if ($row === null) {
            throw new RuntimeException("No period called [{$label}] exists for this batch.");
        }

        return $this->fromRow($batch, $row);
    }

    /**
     * Materialise the period as a row, so notes, reports and grades can reference it by id.
     *
     * Computed types create their row on first use rather than being pre-generated years ahead —
     * a batch that runs for three months should not carry twelve empty periods.
     */
    public function ensure(Batch $batch, PeriodBoundary $boundary): ReportingPeriod
    {
        return ReportingPeriod::query()->firstOrCreate(
            [
                'batch_id' => $batch->getKey(),
                'label' => $boundary->label,
            ],
            [
                'course_id' => $batch->course_id,
                'type' => $boundary->type,
                'starts_local_date' => $boundary->startsLocalDate->toDateString(),
                'ends_local_date' => $boundary->endsLocalDate->toDateString(),
                'status' => ReportingPeriod::STATUS_OPEN,
            ],
        );
    }

    /**
     * Every period a batch spans, in order. Used by report screens and trend charts.
     *
     * @return array<int, PeriodBoundary>
     */
    public function forBatch(Batch $batch, int $limit = 24): array
    {
        $periods = [];
        $cursor = CarbonImmutable::parse($batch->starts_on->toDateString(), $batch->timezone);
        $end = $batch->ends_on !== null
            ? CarbonImmutable::parse($batch->ends_on->toDateString(), $batch->timezone)
            : $batch->localNow();

        while ($cursor <= $end && count($periods) < $limit) {
            $boundary = $this->forDate($batch, $cursor);
            $periods[] = $boundary;
            $cursor = $boundary->endsLocalDate->addDay()->startOfDay();
        }

        return $periods;
    }

    // ── Computed types ───────────────────────────────────────────────────────

    private function monthly(Batch $batch, CarbonImmutable $localDate): PeriodBoundary
    {
        $local = $localDate->setTimezone($batch->timezone);
        $start = $local->startOfMonth();
        $end = $local->endOfMonth();

        return $this->boundary($batch, PeriodType::Monthly, $start->format('Y-m'), $start, $end);
    }

    private function quarter(Batch $batch, CarbonImmutable $localDate): PeriodBoundary
    {
        $local = $localDate->setTimezone($batch->timezone);
        $anchorMonth = max(1, min(12, $batch->course->period_anchor_month));

        // Months elapsed since the anchor, floored to a three-month block. Handles a financial
        // year starting in April as naturally as one starting in January.
        $monthsSinceAnchor = (($local->month - $anchorMonth) + 12) % 12;
        $blockStartMonth = $local->month - ($monthsSinceAnchor % 3);

        $start = $local->setMonth(1)->setDay(1)->setMonth(max(1, $blockStartMonth))->startOfMonth();

        if ($blockStartMonth < 1) {
            $start = $local->subYear()->setMonth(12 + $blockStartMonth)->startOfMonth();
        }

        $end = $start->addMonths(2)->endOfMonth();
        $quarterNumber = (int) floor(((($start->month - $anchorMonth) + 12) % 12) / 3) + 1;

        return $this->boundary(
            $batch,
            PeriodType::Quarter,
            sprintf('%s-Q%d', $start->format('Y'), $quarterNumber),
            $start,
            $end,
        );
    }

    private function block(Batch $batch, CarbonImmutable $localDate): PeriodBoundary
    {
        $local = $localDate->setTimezone($batch->timezone);
        $weeks = max(1, $batch->course->period_block_weeks);
        $origin = CarbonImmutable::parse($batch->starts_on->toDateString(), $batch->timezone)->startOfDay();

        // Before the batch starts there is no block yet; treat the first one as current so a
        // teacher setting up early is not shown an error.
        $daysIn = max(0, (int) $origin->diffInDays($local, absolute: false));
        $index = intdiv($daysIn, $weeks * 7);

        $start = $origin->addWeeks($index * $weeks);
        $end = $start->addWeeks($weeks)->subDay()->endOfDay();

        return $this->boundary($batch, PeriodType::Block, 'Block '.($index + 1), $start, $end);
    }

    /** Term and custom periods are defined by a person, so they are read rather than computed. */
    private function defined(Batch $batch, CarbonImmutable $localDate): PeriodBoundary
    {
        $date = $localDate->setTimezone($batch->timezone)->toDateString();

        $row = ReportingPeriod::query()
            ->where(fn ($q) => $q->where('batch_id', $batch->getKey())
                ->orWhere(fn ($q2) => $q2->whereNull('batch_id')->where('course_id', $batch->course_id)))
            ->whereDate('starts_local_date', '<=', $date)
            ->whereDate('ends_local_date', '>=', $date)
            ->orderByRaw('batch_id IS NULL')
            ->first();

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'No %s period covers %s for this batch. Define one before reporting on it.',
                $batch->periodType()->label(),
                $date,
            ));
        }

        return $this->fromRow($batch, $row);
    }

    private function fromRow(Batch $batch, ReportingPeriod $row): PeriodBoundary
    {
        return $this->boundary(
            $batch,
            $row->type,
            $row->label,
            CarbonImmutable::parse($row->starts_local_date->toDateString(), $batch->timezone)->startOfDay(),
            CarbonImmutable::parse($row->ends_local_date->toDateString(), $batch->timezone)->endOfDay(),
        );
    }

    /**
     * The conversion that matters: local wall-clock bounds become UTC instants here and nowhere
     * else. A period that ends at 23:59:59 in Toronto ends at 03:59:59 UTC the next day, and an
     * evening session on the 31st stays inside the month it was taught in.
     */
    private function boundary(
        Batch $batch,
        PeriodType $type,
        string $label,
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
    ): PeriodBoundary {
        return new PeriodBoundary(
            type: $type,
            label: $label,
            startsLocalDate: $localStart->startOfDay(),
            endsLocalDate: $localEnd->startOfDay(),
            startsAtUtc: $localStart->startOfDay()->utc(),
            endsAtUtc: $localEnd->endOfDay()->utc(),
            timezone: $batch->timezone,
        );
    }
}
