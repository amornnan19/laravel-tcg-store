@props(['active' => false, 'href' => null])

@php
    $classes = [
        'inline-flex min-h-11 flex-none items-center rounded-full border px-4 text-small transition-[color,background-color,border-color] duration-160 ease-ui motion-reduce:transition-none',
        'border-accent bg-accent font-medium text-accent-ink' => $active,
        'border-line-strong bg-surface text-muted hover:text-ink' => ! $active,
    ];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" @if ($active) aria-current="true" @endif {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
