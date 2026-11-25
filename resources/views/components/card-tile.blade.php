@props(['printing'])

@php
    $stock = $printing->stockSummary();
    $hasFoil = $printing->hasAvailableFoil();
    $priceColour = match (true) {
        ! $stock['in_stock'] => 'text-faint',
        in_array($printing->rarity_code, ['sar', 'sec'], true) => 'text-accent',
        default => '',
    };
@endphp

<a
    href="{{ route('card.show', ['card' => $printing->card, 'p' => $printing->id]) }}"
    {{ $attributes->class(['flex min-h-11 flex-col gap-3 rounded-card border border-line bg-surface p-3 text-ink transition-[border-color,background-color] duration-160 ease-ui hover:border-line-strong hover:bg-raised motion-reduce:transition-none', 'opacity-55' => ! $stock['in_stock']]) }}
>
    <span class="relative block">
        <x-card-art :foil="$hasFoil" size="w-full" radius="rounded-control" />
        <x-rarity-badge :code="$printing->rarity_code" class="absolute left-2 top-2" />
        @if ($hasFoil)
            <span class="absolute bottom-2 right-2 rounded-tag bg-bg/70 px-2 py-1 text-label font-medium text-accent">{{ __('storefront.tile.foil') }}</span>
        @endif
    </span>
    <span class="block min-w-0">
        <span class="block truncate text-small font-medium">{{ $printing->display_name }}</span>
        <span class="mt-1 block truncate text-label text-faint">{{ $printing->display_code }} · {{ $printing->language->shortCode() }}</span>
        <span class="mt-2 flex items-baseline justify-between gap-2">
            @if (($stock['from_satang'] ?? $stock['from_satang_any']) !== null)
                <x-price :satang="$stock['from_satang'] ?? $stock['from_satang_any']" :class="trim('text-card font-medium '.$priceColour)" />
            @else
                <span></span>
            @endif
            <x-stock-indicator :in-stock="$stock['in_stock']" :qty="$stock['available_qty']" />
        </span>
    </span>
</a>
