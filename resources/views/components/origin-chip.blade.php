@props(['level', 'overridden' => false, 'title' => null])

{{--
  Which level supplied a value.

  The product's central claim is that configuration is traceable, so this is a
  component that appears wherever a resolved value is shown rather than a
  feature of one settings page. Grey means inherited; amber means someone
  changed it at this level.
--}}
<span
    @if ($title) title="{{ $title }}" @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-mono text-[10px] leading-none',
        'bg-accent-soft text-accent ring-1 ring-accent/20' => $overridden,
        'bg-line-soft text-faint' => ! $overridden,
    ]) }}
>
    @if ($overridden)
        <span class="size-1 rounded-full bg-accent" aria-hidden="true"></span>
    @endif
    {{ $level }}
</span>
