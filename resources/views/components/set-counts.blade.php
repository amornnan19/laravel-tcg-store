@props(['set'])

{{-- Distinct card numbers against card_count, never raw printings (see CardSet::loadNumberCounts). --}}
<span {{ $attributes }}>{{ __('storefront.home.set_cards', ['count' => $set->card_count]) }}@if ($set->extra_numbers > 0) {{ __('storefront.home.set_extras', ['n' => $set->extra_numbers]) }}@endif · {{ __('storefront.home.set_in_stock', ['n' => $set->in_stock_numbers]) }}</span>
