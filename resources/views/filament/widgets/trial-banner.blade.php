@php $urgent = ($trial['days_left'] ?? 99) <= 3; @endphp

<x-filament::section :heading="null">
    <div class="flex flex-wrap items-center gap-4">
        <div class="flex-1">
            <p class="text-sm font-medium">
                {{ $trial['days_left'] }}
                {{ \Illuminate\Support\Str::plural('day', $trial['days_left']) }} left on your trial
            </p>

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                @if (! ($trial['ready_to_teach'] ?? false))
                    {{-- The useful message for someone who has not started is help,
                         not urgency. --}}
                    You have not set up a class yet, so nothing here is doing anything for you.
                    That is the next step, and it takes about five minutes.
                @else
                    If you add a payment method, nothing changes. If you do not, the account becomes
                    read-only — everything you have entered stays exactly as it is, you can still
                    read and export all of it, and paying later restores full access immediately.
                @endif
            </p>
        </div>

        <x-filament::button
            :color="$urgent ? 'warning' : 'gray'"
            tag="a"
            :href="route('billing')"
        >
            {{ ($trial['ready_to_teach'] ?? false) ? 'Add a payment method' : 'Finish setting up' }}
        </x-filament::button>
    </div>
</x-filament::section>
