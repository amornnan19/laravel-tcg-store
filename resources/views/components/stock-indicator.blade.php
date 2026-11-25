@props(['inStock', 'qty'])

{{-- Same thresholds as the search dropdown: sold out, 1-2 left, then "40+" above 40. --}}
@if (! $inStock)
    <span {{ $attributes->class('text-label text-faint') }}>{{ __('storefront.stock.out_of_stock') }}</span>
@elseif ($qty <= 2)
    <span {{ $attributes->class('text-label tabular-nums text-low') }}>{{ __('storefront.stock.low_stock', ['n' => $qty]) }}</span>
@else
    <span {{ $attributes->class('text-label tabular-nums text-stock') }}>{{ __('storefront.stock.in_stock', ['n' => $qty > 40 ? '40+' : $qty]) }}</span>
@endif
