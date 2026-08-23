<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Enums\SessionStatus;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Holiday;
use App\Models\TimetableSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a batch's weekly timetable into dated sessions.
 *
 * Three things this gets right that a naive implementation does not:
 *
 *  1. Each occurrence is converted from local wall-clock time to UTC **on its own date**. A batch
 *     in Toronto keeps its 09:00 class at 09:00 across the November clock change, while the stored
 *     instant moves by an hour. Applying one offset to the whole range would quietly shift every
 *     session after the transition.
 *
 *  2. Local times that do not exist are refused rather than nudged. On the March spring-forward
 *     date, 02:30 never happens in Toronto; PHP will happily hand back 03:30 instead. That is a
 *     class starting at a time nobody agreed to, so it is reported as a skip for a human to
 *     resolve.
 *
 *  3. Running it twice does nothing the second time. Generation is something coordinators press
 *     when unsure whether they already did.
 */
final class SessionGenerator
{
    public function generate(Batch $batch, CarbonImmutable $from, CarbonImmutable $to): GenerationResult
    {
        $result = new GenerationResult;

        $slots = $batch->timetableSlots()->with('sessionType')->get();

        if ($slots->isEmpty()) {
            return $result;
        }

        $holidays = $this->blockedDates($batch);
        $existing = $this->existingKeys($batch, $from, $to);

        $batchStart = CarbonImmutable::parse($batch->starts_on->toDateString(), $batch->timezone)->startOfDay();
        $batchEnd = $batch->ends_on !== null
            ? CarbonImmutable::parse($batch->ends_on->toDateString(), $batch->timezone)->endOfDay()
            : null;

        DB::transaction(function () use ($batch, $slots, $from, $to, $holidays, $existing, $batchStart, $batchEnd, $result): void {
            $cursor = $from->setTimezone($batch->timezone)->startOfDay();
            $last = $to->setTimezone($batch->timezone)->startOfDay();

            while ($cursor <= $last) {
                foreach ($slots as $slot) {
                    if ($slot->weekday->value !== $cursor->dayOfWeekIso) {
                        continue;
                    }

                    $this->considerDate($batch, $slot, $cursor, $holidays, $existing, $batchStart, $batchEnd, $result);
                }

                $cursor = $cursor->addDay();
            }
        });

        return $result;
    }

    /**
     * @param array<string, string> $holidays date => name
     * @param array<string, true> $existing
     */
    private function considerDate(
        Batch $batch,
        TimetableSlot $slot,
        CarbonImmutable $localDate,
        array $holidays,
        array &$existing,
        CarbonImmutable $batchStart,
        ?CarbonImmutable $batchEnd,
        GenerationResult $result,
    ): void {
        $date = $localDate->toDateString();
        $time = $slot->localTime();

        if ($localDate < $batchStart || ($batchEnd !== null && $localDate > $batchEnd)) {
            $result->skip($date, $time, 'outside_batch_dates');

            return;
        }

        if (! $slot->isEffectiveOn($localDate)) {
            $result->skip($date, $time, 'slot_not_effective');

            return;
        }

        if (isset($holidays[$date])) {
            $result->skip($date, $time, 'holiday', $holidays[$date]);

            return;
        }

        $key = $slot->getKey().'|'.$date;

        if (isset($existing[$key])) {
            $result->skip($date, $time, 'already_exists');

            return;
        }

        $starts = $this->localToUtc($date, $slot->start_time_local, $batch->timezone);

        if ($starts === null) {
            $result->skip(
                $date,
                $time,
                'clock_change',
                "{$time} does not exist on this date in {$batch->timezone} — the clocks go forward. "
                .'Add this session by hand at a time that does.',
            );

            return;
        }

        $session = ClassSession::query()->create([
            'batch_id' => $batch->getKey(),
            'session_type_id' => $slot->session_type_id,
            'session_local_date' => $date,
            'start_time_local' => $slot->start_time_local,
            'starts_at_utc' => $starts,
            'ends_at_utc' => $starts->addMinutes($slot->duration_min),
            'status' => SessionStatus::Scheduled,
            'generated_from_slot_id' => $slot->getKey(),
        ]);

        $existing[$key] = true;
        $result->add($session);
    }

    /**
     * Converts a local wall-clock time to a UTC instant, or null when that local time does not
     * exist on that date.
     *
     * The round-trip check is the whole mechanism: build the instant, send it back to the local
     * zone, and see whether the clock still reads what was asked for. On a spring-forward date it
     * will not, because the requested minute was skipped.
     */
    private function localToUtc(string $date, string $time, string $timezone): ?CarbonImmutable
    {
        $normalised = strlen($time) === 5 ? $time.':00' : $time;

        $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$date} {$normalised}", $timezone);

        if ($local === false) {
            return null;
        }

        if ($local->format('Y-m-d H:i') !== substr("{$date} {$normalised}", 0, 16)) {
            return null;
        }

        return $local->utc();
    }

    /** @return array<string, string> date => holiday name */
    private function blockedDates(Batch $batch): array
    {
        return Holiday::query()
            ->where('branch_id', $batch->branch_id)
            ->where('blocks_sessions', true)
            ->pluck('name', 'date')
            ->mapWithKeys(fn (string $name, $date) => [
                CarbonImmutable::parse($date)->toDateString() => $name,
            ])
            ->all();
    }

    /**
     * Existing occurrences keyed by slot and local date, loaded once rather than queried per day.
     *
     * @return array<string, true>
     */
    private function existingKeys(Batch $batch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ClassSession::withTrashed()
            ->where('batch_id', $batch->getKey())
            ->whereNotNull('generated_from_slot_id')
            ->whereBetween('session_local_date', [
                $from->setTimezone($batch->timezone)->toDateString(),
                $to->setTimezone($batch->timezone)->toDateString(),
            ])
            ->get(['generated_from_slot_id', 'session_local_date'])
            ->mapWithKeys(fn (ClassSession $s) => [
                $s->generated_from_slot_id.'|'.$s->session_local_date->toDateString() => true,
            ])
            ->all();
    }
}
