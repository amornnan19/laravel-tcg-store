@props(['item'])

@php
    $printing = $item->printing;
    $front = $item->photoUrl('front_path');
@endphp

<a
    href="{{ route('graded.show', $item) }}"
    {{ $attributes->class('flex min-h-11 flex-col gap-2 rounded-card border border-line bg-surface p-3 text-ink transition-[border-color,background-color] duration-160 ease-ui hover:border-line-strong hover:bg-raised motion-reduce:transition-none') }}
>
    <span class="relative block">
        @if ($front !== null)
            <img src="{{ $front }}" alt="" loading="lazy" class="aspect-[3/4] w-full rounded-control border border-line bg-sunken object-cover">
        @else
            <x-card-art size="w-full" radius="rounded-control" />
        @endif
        <x-grade-badge :label="$item->gradeBadge()" class="absolute left-2 top-2 bg-bg/80" />
    </span>
    <span class="block min-w-0">
        <span class="block truncate text-label text-faint">{{ $printing->display_code }} · {{ $printing->language->shortCode() }}@if (filled($item->grade_label)) · {{ $item->grade_label }}@endif</span>
        <x-price :satang="$item->unitPriceSatang()" class="mt-1 block text-card font-medium" />
    </span>
    <span class="inline-flex h-6 items-center self-start rounded-full border border-line-strong bg-raised px-3 text-label text-muted">{{ __('storefront.graded.tile_photos') }}</span>
</a>
