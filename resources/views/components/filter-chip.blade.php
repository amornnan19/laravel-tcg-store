@props(['active' => false, 'href' => null, 'remove' => false, 'tone' => null])

{{-- `remove` marks a chip that takes its filter off (an applied filter), not a toggle: no aria-pressed, a cross after the label. `tone` replaces the resting colours (rarity chips wear their own). --}}
@php
    $classes = [
        'inline-flex min-h-11 flex-none items-center gap-2 rounded-full border px-4 text-small transition-[color,background-color,border-color] duration-160 ease-ui motion-reduce:transition-none',
        'border-accent bg-accent font-medium text-accent-ink' => $active,
        ($tone ?? 'border-line-strong bg-surface text-muted hover:text-ink') => ! $active,
    ];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" @if ($active) aria-current="true" @endif {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($remove)
    <button type="button" {{ $attributes->class($classes) }}>{{ $slot }}<svg width="10" height="10" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="flex-none"><path d="m2 2 8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
@else
    <button type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
