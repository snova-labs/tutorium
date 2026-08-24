@php
    $grid = $this->grid();
    $statuses = $this->submissionStatuses();
    $exempt = $statuses->firstWhere('excluded_from_average', true);
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-baseline gap-3">
        <h1 class="text-xl font-semibold tracking-tight">{{ $this->batch()->name }}</h1>
        <span class="font-mono text-xs text-faint">{{ $this->periodLabel }}</span>
    </div>

    @if ($grid['assessments']->isEmpty())
        <div class="card p-8 text-center">
            <p class="text-sm text-muted">Nothing has been set for this period yet.</p>
        </div>
    @else

    <div class="card overflow-x-auto">
        <table class="w-full min-w-3xl border-collapse text-sm">
            <thead>
                <tr class="border-b border-line">
                    <th class="sticky left-0 z-10 bg-surface px-3 py-2.5 text-left text-xs
                               font-medium text-muted">Learner</th>

                    @foreach ($grid['assessments'] as $assessment)
                        <th class="px-3 py-2.5 text-right align-bottom">
                            <div class="text-xs font-medium">{{ $assessment['title'] }}</div>
                            {{-- The scheme is named in the header, because a column
                                 of "17" means nothing without "out of 20". --}}
                            <div class="font-mono text-[10px] font-normal text-faint">
                                {{ $assessment['type'] }} ·
                                {{ $assessment['scheme']['label'] }}
                                @if ($assessment['scheme']['max_points'])
                                    /{{ rtrim(rtrim((string) $assessment['scheme']['max_points'], '0'), '.') }}
                                @endif
                            </div>
                        </th>
                    @endforeach

                    <th class="px-3 py-2.5 text-right text-xs font-medium text-muted">Average</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line-soft">
                @foreach ($grid['rows'] as $row)
                    @php $average = $this->average($row['enrollment_id']); @endphp

                    <tr wire:key="row-{{ $row['enrollment_id'] }}" class="hover:bg-page/60">
                        <td class="sticky left-0 z-10 bg-surface px-3 py-2">
                            <div class="text-sm font-medium">{{ $row['name'] }}</div>
                            <div class="font-mono text-[10px] text-faint">{{ $row['number'] }}</div>
                        </td>

                        @foreach ($grid['assessments'] as $assessment)
                            @php
                                $key = $row['enrollment_id'].':'.$assessment['id'];
                                $kind = $assessment['scheme']['kind'];
                            @endphp

                            <td class="px-3 py-2 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($kind === 'pass_fail')
                                        <select class="input w-24 py-1 text-xs"
                                                wire:model.live="cells.{{ $key }}.passed">
                                            <option value="">—</option>
                                            <option value="1">Pass</option>
                                            <option value="0">Fail</option>
                                        </select>
                                    @elseif ($kind === 'letter' || $kind === 'level')
                                        <input type="text" class="input w-20 py-1 text-right font-mono text-xs"
                                               wire:model.live.debounce.400ms="cells.{{ $key }}.{{ $kind === 'letter' ? 'letter' : 'level_code' }}">
                                    @else
                                        <input type="number" step="0.5" min="0"
                                               class="input w-20 py-1 text-right font-mono text-xs"
                                               wire:model.live.debounce.400ms="cells.{{ $key }}.raw_score">
                                    @endif

                                    <select class="input w-10 py-1 text-center text-[10px]"
                                            wire:model.live="cells.{{ $key }}.submission_status_id"
                                            title="Submission status">
                                        <option value="">·</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status->id }}">
                                                {{ mb_substr($status->name, 0, 2) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </td>
                        @endforeach

                        <td class="px-3 py-2 text-right">
                            <button type="button"
                                    class="font-mono text-sm font-semibold hover:underline"
                                    wire:click="explain({{ $row['enrollment_id'] }})"
                                    title="Show how this was calculated">
                                {{ $average['percentage'] === null ? '—' : $average['percentage'].'%' }}
                            </button>

                            @unless ($average['is_complete'])
                                {{-- Never folded into the percentage. Two ungraded
                                     of five is neither 100% nor 60% honestly. --}}
                                <div class="text-[10px] text-warn">{{ $average['ungraded'] }} ungraded</div>
                            @endunless
                        </td>
                    </tr>

                    @if ($this->explaining === $row['enrollment_id'])
                        <tr class="bg-page">
                            <td colspan="{{ $grid['assessments']->count() + 2 }}" class="px-3 py-3">
                                {{-- The arithmetic, in full. This panel is the reason
                                     a disputed number is answerable rather than
                                     arguable. --}}
                                <div class="mx-auto max-w-lg">
                                    <p class="mb-2 text-xs text-muted">{{ $average['explanation'] }}</p>

                                    <table class="w-full text-xs">
                                        @foreach ($average['breakdown'] as $line)
                                            <tr class="{{ $line['counted'] === 0 ? 'text-faint' : '' }}">
                                                <td class="py-0.5">{{ $line['type'] }}</td>
                                                <td class="py-0.5 text-right font-mono">
                                                    {{ $line['counted'] === 0 ? '—' : $line['mean'] }}
                                                </td>
                                                <td class="py-0.5 text-right font-mono text-faint">
                                                    {{ $line['weight'] === null ? 'unweighted' : '× '.$line['weight'].'%' }}
                                                </td>
                                                <td class="py-0.5 text-right font-mono">
                                                    {{ $line['contribution'] ?? ($line['counted'] === 0 ? 'excluded' : '—') }}
                                                </td>
                                            </tr>
                                        @endforeach
                                        <tr class="border-t border-line font-semibold">
                                            <td class="py-1" colspan="3">
                                                Weights applied: {{ $average['weights_used'] }}%
                                            </td>
                                            <td class="py-1 text-right font-mono">
                                                {{ $average['percentage'] }}%
                                            </td>
                                        </tr>
                                    </table>

                                    @if ($average['excluded'] > 0 && $exempt)
                                        <p class="mt-2 text-[11px] text-muted">
                                            {{ $average['excluded'] }} marked
                                            {{ mb_strtolower($exempt->name) }} — removed from the
                                            denominator and the remaining weights rescaled, rather
                                            than scored zero.
                                        </p>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sticky bottom-0 -mx-4 mt-4 border-t border-line bg-surface/95 px-4 py-3
                backdrop-blur sm:-mx-6 sm:px-6">
        @if ($this->error)
            <p class="mb-2 text-xs text-bad">{{ $this->error }}</p>
        @endif

        <div class="flex items-center gap-3">
            <button type="button" class="btn btn-primary" wire:click="save"
                    wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save grades</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>

            <span class="text-xs text-muted">
                {{ count($this->dirty) }} unsaved
                {{ Str::plural('change', count($this->dirty)) }}
            </span>

            @if ($this->saved)
                <x-pill tone="ok" dot class="ml-auto">Saved</x-pill>
            @endif
        </div>
    </div>

    @endif
</div>
