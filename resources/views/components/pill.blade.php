@props(['tone' => 'neutral', 'dot' => false])

@php
    $tones = [
        'neutral' => 'bg-line-soft text-muted',
        'ok' => 'bg-ok-soft text-ok ring-1 ring-ok/20',
        'warn' => 'bg-warn-soft text-warn ring-1 ring-warn/20',
        'bad' => 'bg-bad-soft text-bad ring-1 ring-bad/20',
        'accent' => 'bg-accent-soft text-accent ring-1 ring-accent/20',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
    $tones[$tone] ?? $tones['neutral'],
]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
