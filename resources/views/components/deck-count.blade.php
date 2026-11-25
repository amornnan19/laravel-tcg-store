@props(['label', 'count', 'tone', 'href' => null])

{{-- One count of the deck summary: a link to its section when the section exists. --}}
@php
    $classes = 'flex min-h-11 flex-col justify-center rounded-control border border-line px-3 py-2 transition-[border-color] duration-160 ease-ui motion-reduce:transition-none';
@endphp

@if ($href !== null && $count > 0)
    <a href="{{ $href }}" {{ $attributes->class([$classes, 'hover:border-line-strong']) }}>
        <span @class(['text-small', $tone])>{{ $label }}</span>
        <span class="text-h3 font-medium tabular-nums">{{ $count }}</span>
    </a>
@else
    <div {{ $attributes->class([$classes]) }}>
        <span @class(['text-small', $tone])>{{ $label }}</span>
        <span class="text-h3 font-medium tabular-nums">{{ $count }}</span>
    </div>
@endif
