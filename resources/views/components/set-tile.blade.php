@props(['printing', 'showLanguage' => false])

{{-- The dense tile of the set browser: the card number leads, since the grid is read in number order. Sold out stays a link (the card page lists the rows) but is dimmed. --}}
@php
    $stock = $printing->stockSummary();
    $price = $stock['from_satang'] ?? $stock['from_satang_any'];
@endphp

<a
    href="{{ route('card.show', ['card' => $printing->card, 'p' => $printing->id]) }}"
    {{ $attributes->class(['flex min-h-11 flex-col gap-2 rounded-card border border-line bg-surface p-2 text-ink transition-[border-color,background-color] duration-160 ease-ui hover:border-line-strong hover:bg-raised motion-reduce:transition-none', 'opacity-55' => ! $stock['in_stock']]) }}
>
    <x-card-art :foil="$printing->hasAvailableFoil()" size="w-full" radius="rounded-control" />
    <span class="flex items-baseline justify-between gap-2 text-label">
        <span class="min-w-0 truncate tabular-nums text-faint">{{ $printing->number.($showLanguage ? ' · '.$printing->language->shortCode() : '') }}</span>
        <x-rarity-badge :code="$printing->rarity_code" class="h-auto flex-none bg-transparent px-0" />
    </span>
    <span class="block truncate text-label text-muted">{{ $printing->display_name }}</span>
    <span class="flex items-baseline justify-between gap-2">
        @if ($price !== null)
            <x-price :satang="$price" :class="$stock['in_stock'] ? 'text-small font-medium' : 'text-small text-faint'" />
        @else
            <span></span>
        @endif
        @unless ($stock['in_stock'])
            <x-stock-indicator :in-stock="false" :qty="0" />
        @endunless
    </span>
</a>
