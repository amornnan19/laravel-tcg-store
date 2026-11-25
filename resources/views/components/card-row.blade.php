@props(['printing'])

{{-- The list-mode twin of <x-card-tile>: the same printing, the same facts, one row. --}}
@php
    $stock = $printing->stockSummary();
    $hasFoil = $printing->hasAvailableFoil();
    $price = $stock['from_satang'] ?? $stock['from_satang_any'];
    $priceColour = match (true) {
        ! $stock['in_stock'] => 'text-faint',
        in_array($printing->rarity_code, ['sar', 'sec'], true) => 'text-accent',
        default => '',
    };
@endphp

<a
    href="{{ route('card.show', ['card' => $printing->card, 'p' => $printing->id]) }}"
    {{ $attributes->class(['flex min-h-16 items-center gap-3 rounded-card border border-line bg-surface p-3 text-ink transition-[border-color,background-color] duration-160 ease-ui hover:border-line-strong hover:bg-raised motion-reduce:transition-none', 'opacity-55' => ! $stock['in_stock']]) }}
>
    <x-card-art :foil="$hasFoil" size="w-12 lg:w-14" />
    <span class="min-w-0 flex-1">
        <span class="block truncate text-body font-medium">{{ $printing->display_name }}</span>
        <span class="mt-1 block truncate text-label text-faint">{{ $printing->display_code }}</span>
        <span class="mt-2 flex flex-wrap items-center gap-1">
            <x-rarity-badge :code="$printing->rarity_code" class="border border-line" />
            <x-language-badge :language="$printing->language" />
            @foreach ($stock['conditions'] as $condition)
                <x-condition-badge :condition="$condition" />
            @endforeach
            @if ($hasFoil)
                <span class="inline-flex h-6 items-center rounded-tag border border-line px-2 text-label font-medium text-accent">{{ __('storefront.tile.foil') }}</span>
            @endif
        </span>
    </span>
    <span class="flex-none text-right">
        @if ($price !== null)
            <x-price :satang="$price" :class="trim('block text-card font-medium '.$priceColour)" />
        @endif
        <x-stock-indicator :in-stock="$stock['in_stock']" :qty="$stock['available_qty']" class="mt-1 block" />
    </span>
</a>
