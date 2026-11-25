@props(['hasRealPhoto' => false])

{{-- Generic reassurance only; a promise with a number in it (hours, days, carriers) has to come from the shop. --}}
<ul {{ $attributes->class('flex flex-wrap gap-2') }}>
    @if ($hasRealPhoto)
        <li class="inline-flex h-7 items-center rounded-control border border-line-strong bg-raised px-3 text-label font-medium text-muted">{{ __('storefront.trust.real_photo') }}</li>
    @endif
    <li class="inline-flex h-7 items-center rounded-control border border-stock/40 bg-raised px-3 text-label font-medium text-stock">{{ __('storefront.trust.authentic') }}</li>
    <li class="inline-flex h-7 items-center rounded-control border border-line-strong bg-raised px-3 text-label font-medium text-muted">{{ __('storefront.trust.protective_packing') }}</li>
</ul>
