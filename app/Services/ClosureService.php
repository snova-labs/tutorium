<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The days a branch is closed.
 *
 * Generation skips these and says what it skipped, but a closure added after the fact leaves
 * sessions already on the calendar — so this is also what cancels them, with the closure's own
 * name as the reason a parent will read.
 */
final class ClosureService
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    /** @param array<string, mixed> $attributes */
    public function create(Branch $branch, array $attributes): Holiday
    {
        $date = (string) $attributes['date'];

        if (Holiday::query()->where('branch_id', $branch->getKey())->whereDate('date', $date)->exists()) {
            throw ValidationException::withMessages([
                'date' => 'This location already has a closure on that date.',
            ]);
        }

        return Holiday::query()->create([
            'branch_id' => $branch->getKey(),
            'date' => $date,
            'name' => $attributes['name'],
            'blocks_sessions' => $attributes['blocks_sessions'] ?? true,
        ]);
    }

    public function remove(Holiday $holiday): void
    {
        // Removing a closure never reinstates a cancelled session: a cancellation is a fact the
        // class was told about, so it is undone deliberately, one session at a time.
        $holiday->delete();
    }

    /**
     * The sessions still standing on that day at that branch.
     *
     * @return Collection<int, ClassSession>
     */
    public function sessionsOn(Holiday $holiday): Collection
    {
        return ClassSession::query()
            ->with('batch')
            ->whereDate('session_local_date', $holiday->date->toDateString())
            ->where('status', SessionStatus::Scheduled)
            ->whereHas('batch', fn ($query) => $query->where('branch_id', $holiday->branch_id))
            ->orderBy('start_time_local')
            ->get();
    }

    /** Cancel what is already on the calendar that day. Returns how many were cancelled. */
    public function cancelSessions(Holiday $holiday, ?string $reason = null): int
    {
        $sessions = $this->sessionsOn($holiday);

        if ($sessions->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($sessions, $holiday, $reason): int {
            foreach ($sessions as $session) {
                $this->scheduling->cancel($session, $reason ?? sprintf('Closed: %s', $holiday->name));
            }

            return $sessions->count();
        });
    }
}
