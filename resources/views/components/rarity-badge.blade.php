@props(['code'])

@php
    $colour = match ($code) {
        'u' => 'text-rarity-u',
        'r' => 'text-rarity-r',
        'rr' => 'text-rarity-rr',
        'sar' => 'text-rarity-sar',
        'sec' => 'text-rarity-sec',
        default => 'text-rarity-c',
    };
@endphp

{{-- Letters only, never a fill; the translucent ground keeps them legible over card art. --}}
<span {{ $attributes->class([$colour, 'inline-flex h-6 items-center rounded-tag bg-bg/70 px-2 text-label font-medium']) }}>{{ strtoupper($code) }}</span>
