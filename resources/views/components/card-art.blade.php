@props(['size' => 'w-9'])

{{-- Placeholder art only: official card images are never fetched, stored or hotlinked. The 63:88 ratio lives here and nowhere else. --}}
<span aria-hidden="true" {{ $attributes->class([$size, 'relative aspect-[63/88] flex-none overflow-hidden rounded-tag border border-line bg-surface before:absolute before:inset-0 before:bg-raised before:[clip-path:polygon(0_0,100%_0,100%_62%,0_48%)]']) }}></span>
