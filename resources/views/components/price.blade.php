@props(['satang', 'compareAt' => null])

@if ($compareAt !== null && $compareAt > $satang)
    <span {{ $attributes->class('tabular-nums') }}>{{ \App\Support\Money::fromSatang($satang)->format() }} <s class="ml-1 text-small font-normal text-faint"><span class="sr-only">{{ __('storefront.price.was') }}</span>{{ \App\Support\Money::fromSatang($compareAt)->format() }}</s></span>
@else
    <span {{ $attributes->class('tabular-nums') }}>{{ \App\Support\Money::fromSatang($satang)->format() }}</span>
@endif
