@php
    $item = $this->item;
    $state = $this->state;
    $minutes = $this->myHold === null ? (int) config('shop.reservation_minutes') : max(1, (int) ceil(now()->diffInSeconds($this->myHold->expires_at, false) / 60));
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-6 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $card = $item?->printing?->card;
    $ungradedUrl = $card === null ? null : route('card.show', ['card' => $card, 'p' => $item->printing_id]);
    $othersUrl = $ungradedUrl !== null && $this->hasOtherGraded ? $ungradedUrl.'#graded-heading' : null;
@endphp

{{-- One root. The state, not a disabled button, explains why there is nothing to add. While another cart holds the item
     the box polls, so it turns into an add button on its own once that hold ends. --}}
<div @if ($state === 'taken') wire:poll.30s @endif class="flex flex-col gap-3">
    @if ($item !== null)
        <p @class(['text-h2 font-medium tabular-nums lg:text-h1', 'text-faint' => $state === 'sold'])><x-price :satang="$item->unitPriceSatang()" /></p>
    @endif

    @if ($state === 'available')
        <button type="button" wire:click="add" wire:loading.attr="aria-busy" wire:target="add" class="{{ $primaryButton }} w-full sm:w-auto sm:self-start">{{ __('storefront.graded.add') }}</button>
        <p class="text-small text-muted">{{ __('storefront.graded.one_of_a_kind', ['minutes' => config('shop.reservation_minutes')]) }}</p>
    @elseif ($state === 'mine')
        <p role="status" class="text-small font-medium text-stock">{{ __('storefront.graded.in_cart', ['minutes' => $minutes]) }}</p>
        <a href="{{ route('cart') }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.graded.view_cart') }}</a>
    @elseif ($state === 'taken')
        <p role="status" class="text-small font-medium text-low">{{ __('storefront.graded.taken') }}</p>
        <p class="text-small text-muted">{{ __('storefront.graded.taken_hint', ['minutes' => config('shop.reservation_minutes')]) }}</p>
        @if ($ungradedUrl !== null)
            <a href="{{ $ungradedUrl }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.graded.view_ungraded') }}</a>
        @endif
        @if ($othersUrl !== null)
            <a href="{{ $othersUrl }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.graded.other_graded') }}</a>
        @endif
    @elseif ($state === 'sold')
        <p role="status" class="text-small font-medium text-muted">{{ __('storefront.graded.sold') }}</p>
        @if ($ungradedUrl !== null)
            <a href="{{ $ungradedUrl }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.graded.view_ungraded') }}</a>
        @endif
        @if ($othersUrl !== null)
            <a href="{{ $othersUrl }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.graded.other_graded') }}</a>
        @endif
    @else
        <p role="status" class="text-small text-muted">{{ __('storefront.graded.unavailable') }}</p>
    @endif

    @if ($notice !== null)
        <p role="status" class="text-small text-low">{{ $notice }}</p>
    @endif
</div>
