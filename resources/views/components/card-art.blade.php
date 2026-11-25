@props(['printing' => null, 'size' => 'w-9', 'foil' => null, 'loading' => false, 'radius' => 'rounded-tag'])

@php
    $isFoil = ! $loading && ($foil ?? ($printing?->has_foil ?? false));
@endphp

{{-- Placeholder art only: official card images are never fetched, stored or hotlinked. The 63:88 ratio lives here and nowhere else. --}}
<span aria-hidden="true" {{ $attributes->class([
    $size,
    $radius,
    'relative block aspect-[63/88] flex-none overflow-hidden',
    'skeleton bg-raised' => $loading,
    'foil' => $isFoil,
    'border border-line bg-surface before:absolute before:inset-0 before:bg-raised before:[clip-path:polygon(0_0,100%_0,100%_62%,0_48%)]' => ! $loading,
]) }}></span>
