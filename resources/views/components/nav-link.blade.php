@props(['active' => false])

<a {{ $attributes->class([
    'rounded-md px-3 py-1.5 font-medium',
    'bg-primary-soft text-ink' => $active,
    'text-muted hover:text-ink' => ! $active,
]) }}>{{ $slot }}</a>
