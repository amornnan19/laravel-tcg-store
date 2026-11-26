@props(['presentation', 'size' => 'w-12'])

{{-- Each kind in its own shape: a graded card's real photo (3:4), a sealed product square, a single card the placeholder
     art. Never official art, and never card art for a box. --}}
@if ($presentation?->shape === 'square')
    <x-sealed-art :url="$presentation->imageUrl" :size="$size" {{ $attributes }} />
@elseif ($presentation?->imageUrl !== null)
    <img src="{{ $presentation->imageUrl }}" alt="" loading="lazy" {{ $attributes->class([$size, 'aspect-[3/4] flex-none rounded-tag border border-line bg-surface object-cover']) }}>
@else
    <x-card-art :printing="$presentation?->printing" :size="$size" {{ $attributes }} />
@endif
