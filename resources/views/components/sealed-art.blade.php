@props(['product' => null, 'url' => null, 'label' => false, 'size' => 'w-full'])

@php
    $url ??= $product?->imageUrl();
@endphp

{{-- The staff-uploaded photo, or a neutral block. Never card art: a box is not a card. The kind's name is written inside
     the block only where it is large (the product page), never in a thumbnail. --}}
@if ($url !== null)
    <img src="{{ $url }}" alt="{{ $product === null ? '' : __('storefront.sealed.image_alt', ['name' => $product->displayName()]) }}" loading="lazy" {{ $attributes->class([$size, 'aspect-square flex-none rounded-control border border-line bg-sunken object-contain']) }}>
@else
    <span aria-hidden="{{ $label ? 'false' : 'true' }}" {{ $attributes->class([$size, 'flex aspect-square flex-none items-center justify-center rounded-control border border-line bg-sunken p-3 text-center text-label text-faint']) }}>@if ($label && $product !== null){{ $product->kind->label() }}@endif</span>
@endif
