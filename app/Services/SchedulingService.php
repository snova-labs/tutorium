<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Support\Time\GenerationResult;
use App\Support\Time\PeriodService;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that changes a schedule after it has been generated.
 */
final class SchedulingService
{
    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly PeriodService $periods,
    ) {}

    public function generateRange(Batch $batch, string $from, string $to): GenerationResult
    {
        return $this->generator->generate(
            $batch,
            CarbonImmutable::parse($from, $batch->timezone),
            CarbonImmutable::parse($to, $batch->timezone),
        );
    }

    /** Generate the whole of a named period — the button a coordinator actually presses. */
    public function generatePeriod(Batch $batch, ?string $label = null): GenerationResult
    {
        $boundary = $label === null
            ? $this->periods->current($batch)
            : $this->periods->forLabel($batch, $label);

        $this->periods->ensure($batch, $boundary);

        return $this->generator->generate($batch, $boundary->startsLocalDate, $boundary->endsLocalDate);
    }

    public function cancel(ClassSession $session, string $reason): ClassSession
    {
        if ($session->status === SessionStatus::Cancelled) {
            throw ValidationException::withMessages(['session' => 'This session is already cancelled.']);
        }

        return DB::transaction(function () use ($session, $reason): ClassSession {
            // Cancelling keeps the row and its attendance history. The status is what removes it
            // from the denominator — deleting it would erase the fact that it was ever planned.
            $session->update([
                'status' => SessionStatus::Cancelled,
                'cancel_reason' => $reason,
            ]);

            return $session->refresh();
        });
    }

    public function reschedule(ClassSession $session, string $localDate, string $localTime): ClassSession
    {
        $batch = $session->batch;
        $normalised = strlen($localTime) === 5 ? $localTime.':00' : $localTime;

        $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$localDate} {$normalised}", $batch->timezone);

        if ($local === false || $local->format('Y-m-d H:i') !== substr("{$localDate} {$normalised}", 0, 16)) {
            throw ValidationException::withMessages([
                'starts_at' => "{$localTime} does not exist on {$localDate} in {$batch->timezone}. "
                    .'The clocks change that day — choose another time.',
            ]);
        }

        $duration = (int) $session->starts_at_utc->diffInMinutes($session->ends_at_utc);

        return DB::transaction(function () use ($session, $local, $localDate, $normalised, $duration): ClassSession {
            $session->update([
                'session_local_date' => $localDate,
                'start_time_local' => $normalised,
                'starts_at_utc' => $local->utc(),
                'ends_at_utc' => $local->utc()->addMinutes($duration),
                // A rescheduled session detaches from its slot: it is no longer the timetable's
                // occurrence, so regenerating must not treat that weekday as already covered.
                'generated_from_slot_id' => null,
            ]);

            return $session->refresh();
        });
    }

    public function markHeld(ClassSession $session): ClassSession
    {
        $session->update(['status' => SessionStatus::Held]);

        return $session->refresh();
    }
}
