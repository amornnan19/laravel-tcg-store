@props(['presentation', 'size' => 'w-12'])

{{-- A real photo when the item has one (a graded card's front); otherwise the placeholder card art, never official art. --}}
@if ($presentation?->imageUrl !== null)
    <img src="{{ $presentation->imageUrl }}" alt="" loading="lazy" {{ $attributes->class([$size, 'aspect-[3/4] flex-none rounded-tag border border-line bg-surface object-cover']) }}>
@else
    <x-card-art :printing="$presentation?->printing" :size="$size" {{ $attributes }} />
@endif
