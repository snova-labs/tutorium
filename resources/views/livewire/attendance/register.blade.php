@php
    $session = $this->session();
    $policy = $this->policy();
    $tally = $this->tally();
    $batchZone = $session->batch->timezone;
    $viewerZone = auth()->user()?->timezone;
    $showBoth = $viewerZone !== null && $viewerZone !== $batchZone;
@endphp

<div class="mx-auto max-w-3xl">

    {{-- ── Heading ─────────────────────────────────────────────────────── --}}
    <div class="mb-4">
        <h1 class="text-xl font-semibold tracking-tight">{{ $session->batch->name }}</h1>
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
            <span>{{ $session->session_local_date->format('D j M') }}</span>
            <x-when
                :time="substr((string) $session->start_time_local, 0, 5)"
                :zone="$batchZone"
                :also="$showBoth ? $session->startsAtIn($viewerZone)->format('H:i') : null"
                :also-zone="$showBoth ? $viewerZone : null"
            />
            <x-pill>{{ $session->sessionType->name }}</x-pill>
        </div>
    </div>

    {{-- ── The policy in force ─────────────────────────────────────────────
         Printed above the grid on purpose. A teacher deciding between Late and
         Absent should be able to see what the system will make of either. --}}
    <div class="card mb-4 p-3">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
            <div>
                <span class="text-faint">Late arrivals</span>
                <span class="ml-1.5 font-medium">
                    @if ($policy->allowLateJoin)
                        count as attended, within {{ $policy->lateGraceMinutes }} min
                    @else
                        do not count as attended
                    @endif
                </span>
                <x-origin-chip
                    :level="$policy->originOf('allow_late_join')"
                    :overridden="$policy->originOf('allow_late_join') === 'batch'"
                    title="Set at this level"
                    class="ml-1.5"
                />
            </div>

            @unless ($policy->isCompulsory)
                <x-pill tone="neutral">Recorded for information only</x-pill>
            @endunless

            @unless ($policy->countsSessionType((int) $session->session_type_id))
                <x-pill tone="warn">This type is outside the attendance percentage</x-pill>
            @endunless
        </div>
    </div>

    {{-- ── Bulk actions and filter ─────────────────────────────────────── --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        @foreach ($this->statuses()->where('counts_as_attended', true)->take(1) as $status)
            <button type="button" class="btn" wire:click="markRemaining({{ $status->id }})">
                Mark the rest {{ mb_strtolower($status->name) }}
            </button>
        @endforeach

        <input
            type="search"
            class="input ml-auto w-40 sm:w-56"
            placeholder="Find someone"
            wire:model.live.debounce.200ms="filter"
        >
    </div>

    {{-- ── The register ────────────────────────────────────────────────────
         One row per person, marks as buttons rather than a select. On a phone a
         dropdown is three taps and a scroll; this is one tap. --}}
    <div class="card divide-y divide-line-soft">
        @forelse ($this->visible() as $row)
            @php $current = $this->marks[$row['enrollment_id']] ?? null; @endphp

            <div class="p-3" wire:key="row-{{ $row['enrollment_id'] }}">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">{{ $row['name'] }}</div>
                        <div class="font-mono text-[10px] text-faint">{{ $row['number'] }}</div>
                    </div>

                    <div class="flex shrink-0 gap-1.5">
                        @foreach ($this->statuses() as $status)
                            @php
                                $on = $current === $status->id;
                                $tone = match (true) {
                                    ! $on => '',
                                    $status->is_negative => 'border-bad/30 bg-bad-soft text-bad',
                                    $status->is_late => 'border-warn/30 bg-warn-soft text-warn',
                                    $status->counts_as_attended => 'border-ok/30 bg-ok-soft text-ok',
                                    default => 'border-line bg-line-soft text-ink',
                                };
                            @endphp

                            <button
                                type="button"
                                class="mark {{ $tone }}"
                                wire:click="mark({{ $row['enrollment_id'] }}, {{ $status->id }})"
                                aria-pressed="{{ $on ? 'true' : 'false' }}"
                                aria-label="{{ $status->name }} — {{ $row['name'] }}"
                                title="{{ $status->name }}"
                            >{{ mb_substr($status->name, 0, 1) }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- The note only appears once a mark is made: an empty field per
                     person on a phone is mostly a way to lose the list.

                     `.live.blur` rather than `.blur` — under Livewire v4 the bare
                     modifier controls client-side syncing rather than network
                     timing, and a note typed here would never reach the server.
                     Nothing would error; the note would simply not be saved. --}}
                @if ($current !== null)
                    <input
                        type="text"
                        class="input mt-2 text-xs"
                        placeholder="Add a note (optional)"
                        wire:model.live.blur="notes.{{ $row['enrollment_id'] }}"
                    >
                @endif
            </div>
        @empty
            <p class="p-6 text-center text-sm text-muted">
                @if ($this->filter !== '')
                    Nobody here matches “{{ $this->filter }}”.
                @else
                    Nobody is enrolled in this class yet.
                @endif
            </p>
        @endforelse
    </div>

    {{-- ── Save bar ────────────────────────────────────────────────────────
         Fixed to the bottom, because the list is longer than a phone screen and
         a save button at the end of it is a save button nobody finds. --}}
    <div class="sticky bottom-0 -mx-4 mt-4 border-t border-line bg-surface/95 px-4 py-3
                backdrop-blur sm:-mx-6 sm:px-6">
        @if ($this->error)
            <p class="mb-2 text-xs text-bad">{{ $this->error }}</p>
        @endif

        <div class="flex items-center gap-3">
            <button
                type="button"
                class="btn btn-primary"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
            >
                <span wire:loading.remove wire:target="save">Save attendance</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>

            <div class="min-w-0 text-xs text-muted">
                <div class="font-mono">
                    {{ $tally['marked'] }} of {{ $tally['total'] }} marked
                    @if ($tally['percentage'] !== null)
                        · {{ $tally['percentage'] }}%
                    @endif
                </div>
                @if ($tally['excused'] > 0)
                    {{-- Said out loud, because it is the difference between a fair
                         number and a punitive one. --}}
                    <div class="text-[11px]">
                        {{ $tally['excused'] }} excused — outside the percentage, not counted against them
                    </div>
                @endif
            </div>

            @if ($this->saved)
                <x-pill tone="ok" dot class="ml-auto shrink-0">Saved</x-pill>
            @endif
        </div>
    </div>
</div>
