@props(['tile' => false])

{{-- No spinner: the tile keeps the real 63:88 art box so the layout does not jump when content arrives. --}}
@if ($tile)
    <div aria-hidden="true" {{ $attributes->class('flex flex-col gap-3 rounded-card border border-line bg-surface p-3') }}>
        <x-card-art size="w-full" :loading="true" />
        <div class="flex flex-col gap-2">
            <div class="skeleton relative h-3 w-5/6 overflow-hidden rounded-tag bg-raised"></div>
            <div class="skeleton relative h-2.5 w-3/5 overflow-hidden rounded-tag bg-raised"></div>
            <div class="skeleton relative h-3.5 w-1/2 overflow-hidden rounded-tag bg-raised"></div>
        </div>
    </div>
@else
    <div aria-hidden="true" {{ $attributes->class('skeleton relative overflow-hidden rounded-tag bg-raised') }}></div>
@endif
