<div class="mx-auto max-w-3xl space-y-5">

    <div>
        <h1 class="text-xl font-semibold tracking-tight">
            {{ \Carbon\CarbonImmutable::now(auth()->user()->timezone ?? 'UTC')->format('l j F') }}
        </h1>
        <p class="mt-0.5 text-sm text-muted">
            {{ auth()->user()->name }}
        </p>
    </div>

    {{-- ── Anything left unmarked ──────────────────────────────────────────
         First, and above today, because a gap in a past register is the thing
         that quietly breaks a report weeks later. --}}
    @if ($this->unmarked())
        <div class="card border-warn/30 bg-warn-soft/40 p-4">
            <h2 class="text-sm font-semibold text-warn">Registers not taken</h2>
            <p class="mb-3 mt-0.5 text-xs text-muted">
                These sessions have no attendance recorded. Reports over this period will show the
                gap rather than guess at it.
            </p>

            <ul class="space-y-1.5">
                @foreach ($this->unmarked() as $session)
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate">
                            {{ $session['batch'] }}
                            <span class="text-faint">· {{ $session['date'] }}</span>
                        </span>
                        <a href="{{ route('attendance.register', $session['id']) }}"
                           class="btn shrink-0 px-2.5 py-1 text-xs">Take it</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Today ───────────────────────────────────────────────────────── --}}
    <div>
        <h2 class="mb-2 text-xs font-medium tracking-wide text-muted uppercase">Today</h2>

        @forelse ($this->sessions() as $session)
            <div class="card mb-2 flex items-center gap-3 p-3">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium">{{ $session['batch'] }}</div>
                    <div class="mt-0.5 flex flex-wrap items-center gap-2">
                        <x-when
                            :time="$session['local_time']"
                            :zone="$session['local_zone']"
                            :also="$session['viewer_time']"
                            :also-zone="$session['viewer_zone']"
                        />
                        <span class="text-[10px] text-faint">{{ $session['type'] }}</span>
                    </div>
                </div>

                @if ($session['marked'] > 0)
                    <x-pill tone="ok" dot>{{ $session['marked'] }} marked</x-pill>
                @elseif ($session['past'])
                    <x-pill tone="warn">Not taken</x-pill>
                @else
                    <x-pill>Upcoming</x-pill>
                @endif

                <a href="{{ route('attendance.register', $session['id']) }}"
                   class="btn shrink-0 {{ $session['marked'] === 0 && $session['past'] ? 'btn-primary' : '' }}">
                    {{ $session['marked'] > 0 ? 'Open' : 'Take register' }}
                </a>
            </div>
        @empty
            <div class="card p-6 text-center">
                <p class="text-sm text-muted">Nothing scheduled today.</p>
            </div>
        @endforelse
    </div>
</div>
