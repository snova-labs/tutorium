@props(['time', 'zone', 'also' => null, 'alsoZone' => null])

{{--
  A time that names its own zone.

  The second device from the design system. One tenant may teach from Kathmandu
  to Toronto, so a bare "10:00" is ambiguous in a way that costs somebody a
  class. Where two zones are in play, both are shown and the batch's own is
  authoritative — the viewer's is a courtesy.
--}}
<span {{ $attributes->class('inline-flex items-baseline gap-1.5 whitespace-nowrap') }}>
    <span class="font-mono text-sm text-ink">{{ $time }}</span>
    <span class="font-mono text-[10px] text-faint">{{ $zone }}</span>

    @if ($also)
        <span class="text-faint" aria-hidden="true">→</span>
        <span class="font-mono text-sm text-muted">{{ $also }}</span>
        <span class="font-mono text-[10px] text-faint">{{ $alsoZone }}</span>
    @endif
</span>
