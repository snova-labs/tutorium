<x-filament-panels::page>
    @php $resolved = $this->resolved(); @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            {{ $this->form }}
        </div>

        {{-- The resolution rail. The product's central claim, made legible:
             where each value came from, and what it would fall back to. --}}
        <aside class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">Where these come from</x-slot>

                <dl class="space-y-2 text-sm">
                    @foreach (($resolved['origins'] ?? []) as $key => $level)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">
                                {{ ucfirst(str_replace('_', ' ', $key)) }}
                            </dt>
                            <dd>
                                <span @class([
                                    'rounded px-1.5 py-0.5 font-mono text-[10px]',
                                    'bg-amber-50 text-amber-700 ring-1 ring-amber-200' => $level === 'batch',
                                    'bg-gray-100 text-gray-500 dark:bg-gray-800' => $level !== 'batch',
                                ])>{{ $level }}</span>
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Amber means the value was set here. Grey means it is inherited, and will follow
                    any future change made at that level.
                </p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">What a change affects</x-slot>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Policy decides what a mark <em>means</em>, never what it is. Switching late
                    arrivals off does not edit a single register — it changes how the same marks are
                    counted from that moment on, in every figure and every report.
                </p>
            </x-filament::section>
        </aside>
    </div>
</x-filament-panels::page>
