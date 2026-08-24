<?php

declare(strict_types=1);

namespace App\Livewire\Attendance;

use App\Models\AttendanceStatus;
use App\Models\ClassSession;
use App\Services\AttendanceService;
use App\Support\Attendance\AttendancePolicyResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The register.
 *
 * The screen that decides whether this product gets used. A teacher opens it
 * standing up, on a phone, on a weak connection, with a class in front of them.
 * Three things follow from that and shape everything below:
 *
 *  - Marks are held in component state and saved in one action, so a dropped
 *    connection mid-register loses nothing.
 *  - Nothing is loaded lazily inside the loop; the roster arrives once.
 *  - The policy in force is printed above the grid, because a teacher choosing
 *    between Late and Absent should be able to see what the system will make of
 *    either (FR-ATT-5).
 */
final class Register extends Component
{
    #[Locked]
    public int $sessionId;

    /** @var array<int, int> enrollment id => status id */
    public array $marks = [];

    /** @var array<int, string> enrollment id => note */
    public array $notes = [];

    public string $filter = '';

    public bool $saved = false;

    public ?string $error = null;

    public function mount(ClassSession $session): void
    {
        $this->authorize('viewRoster', $session);

        $this->sessionId = $session->getKey();

        // Existing marks are loaded so that reopening a saved register shows what
        // was recorded rather than an empty grid.
        foreach ($this->roster as $row) {
            if ($row['status_id'] !== null) {
                $this->marks[$row['enrollment_id']] = $row['status_id'];
            }

            if ($row['note'] !== null) {
                $this->notes[$row['enrollment_id']] = $row['note'];
            }
        }
    }

    #[Computed(persist: true)]
    public function session(): ClassSession
    {
        return ClassSession::query()
            ->with(['batch.course', 'sessionType'])
            ->findOrFail($this->sessionId);
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed(persist: true)]
    public function roster(): array
    {
        return app(AttendanceService::class)->roster($this->session())->all();
    }

    /** @return \Illuminate\Support\Collection<int, AttendanceStatus> */
    #[Computed(persist: true)]
    public function statuses(): \Illuminate\Support\Collection
    {
        return AttendanceStatus::query()->where('is_active', true)->orderBy('sort')->get();
    }

    #[Computed(persist: true)]
    public function policy(): \App\Support\Attendance\ResolvedAttendancePolicy
    {
        return app(AttendancePolicyResolver::class)->for($this->session()->batch);
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function visible(): array
    {
        if (trim($this->filter) === '') {
            return $this->roster();
        }

        $needle = mb_strtolower(trim($this->filter));

        return array_values(array_filter(
            $this->roster(),
            fn (array $row) => str_contains(mb_strtolower($row['name']), $needle)
                || str_contains(mb_strtolower((string) $row['number']), $needle),
        ));
    }

    /**
     * The running count, read from the same rules the saved figure will use.
     *
     * Shown live so that a teacher sees the consequence of a mark while they can
     * still change their mind about it.
     *
     * @return array<string, int|float|null>
     */
    #[Computed]
    public function tally(): array
    {
        $statuses = $this->statuses()->keyBy('id');
        $attended = 0;
        $counted = 0;
        $excused = 0;

        foreach ($this->marks as $statusId) {
            $status = $statuses->get($statusId);

            if ($status === null) {
                continue;
            }

            if (! $status->counts_in_rate) {
                $excused++;

                continue;
            }

            $counted++;

            if ($this->policy()->treatsAsAttended($status->counts_as_attended, $status->is_late)) {
                $attended++;
            }
        }

        return [
            'marked' => count($this->marks),
            'total' => count($this->roster()),
            'attended' => $attended,
            'counted' => $counted,
            'excused' => $excused,
            'percentage' => $counted === 0 ? null : round(($attended / $counted) * 100, 1),
        ];
    }

    public function mark(int $enrollmentId, int $statusId): void
    {
        // Tapping the same mark twice clears it, so a mis-tap is one tap to undo
        // rather than a trip to a menu.
        if (($this->marks[$enrollmentId] ?? null) === $statusId) {
            unset($this->marks[$enrollmentId]);
        } else {
            $this->marks[$enrollmentId] = $statusId;
        }

        $this->saved = false;
    }

    public function markRemaining(int $statusId): void
    {
        foreach ($this->roster() as $row) {
            // Deliberately does not overwrite an existing mark: a teacher who has
            // already recorded one absence should not lose it to "all present".
            $this->marks[$row['enrollment_id']] ??= $statusId;
        }

        $this->saved = false;
    }

    public function save(): void
    {
        $this->error = null;

        $session = $this->session();
        $this->authorize('record', $session);

        if ($this->marks === []) {
            $this->error = 'Nothing to save yet.';

            return;
        }

        try {
            app(AttendanceService::class)->record($session, collect($this->marks)
                ->map(fn (int $statusId, int $enrollmentId) => [
                    'enrollment_id' => $enrollmentId,
                    'status_id' => $statusId,
                    'note' => $this->notes[$enrollmentId] ?? null,
                ])->values()->all());
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }

        $this->saved = true;
        unset($this->roster);

        $this->dispatch('saved');
    }

    public function render(): View
    {
        return view('livewire.attendance.register')->layout('layouts.app');
    }
}
