@props(['product'])

{{-- A row, like the set rows beside it: the art without text, the name and "game · kind", price and stock on the right.
     `game_name` is selected by the home query. --}}
<a
    href="{{ route('sealed.show', $product) }}"
    {{ $attributes->class('flex min-h-11 items-center gap-3 rounded-card border border-line bg-surface p-3 text-ink transition-[border-color,background-color] duration-160 ease-ui hover:border-line-strong hover:bg-raised motion-reduce:transition-none') }}
>
    <x-sealed-art :product="$product" size="w-16" />
    <span class="min-w-0 flex-1">
        <span class="block truncate text-small font-medium">{{ $product->displayName() }}</span>
        <span class="mt-1 block truncate text-label text-faint">{{ collect([$product->getAttribute('game_name'), $product->kind->label()])->filter()->implode(' · ') }}</span>
    </span>
    <span class="flex-none text-right">
        <x-price :satang="$product->price_satang" class="block text-card font-medium" />
        <x-stock-indicator :in-stock="$product->isAvailable()" :qty="$product->availableQty()" class="mt-1 block" />
    </span>
</a>
