@php
    $product = $this->product;
    $state = $this->state;
    $cap = $this->cap;
    $quantity = $this->quantity();
    $atMin = $quantity <= 1;
    $atMax = $quantity >= $cap;
    $held = $this->myHold;
    // The last unit went into this cart: nothing is left to sell, but the product is not sold out for the visitor.
    $allMine = $state === 'sold_out' && $this->myHold !== null;
    $minutes = $held === null ? 0 : max(1, (int) ceil(now()->diffInSeconds($held->expires_at, false) / 60));
    $stepperButton = 'size-11 text-card transition-[background-color] duration-160 ease-ui motion-reduce:transition-none';
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-6 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
@endphp

{{-- One root. The state, not a disabled button, explains why there is nothing to add. --}}
<div class="flex flex-col gap-3">
    @if ($product !== null && $product->price_satang !== null)
        <p @class(['text-h2 font-medium tabular-nums lg:text-h1', 'text-faint' => $state !== 'available' && ! $allMine, 'text-ink' => $state === 'available' || $allMine])><x-price :satang="$product->price_satang" /></p>
    @endif

    @if ($state === 'preorder')
        <p role="status" class="text-small font-medium text-muted">{{ __('storefront.sealed.preorder_soon') }}</p>
    @elseif ($state === 'unavailable')
        <p role="status" class="text-small text-muted">{{ __('storefront.sealed.unavailable') }}</p>
    @else
        @if ($state === 'available')
            <p><x-stock-indicator :in-stock="true" :qty="$cap" /></p>

            <div class="flex flex-wrap items-center gap-3">
                {{-- No overflow-hidden here: it would clip the focus ring. A button at its limit is aria-disabled, not disabled, so focus stays on it; the actions clamp anyway. --}}
                <span class="inline-flex flex-none items-center rounded-control border border-line-strong bg-surface">
                    <button type="button" wire:click="decrement" @if ($atMin) aria-disabled="true" @endif aria-label="{{ __('storefront.card.decrease') }}" @class([$stepperButton, 'rounded-l-control', 'text-faint' => $atMin, 'text-ink hover:bg-raised' => ! $atMin])>&minus;</button>
                    <span class="min-w-9 text-center text-body font-medium tabular-nums" aria-live="polite">{{ $quantity }}</span>
                    <button type="button" wire:click="increment" @if ($atMax) aria-disabled="true" @endif aria-label="{{ __('storefront.card.increase') }}" @class([$stepperButton, 'rounded-r-control', 'text-faint' => $atMax, 'text-ink hover:bg-raised' => ! $atMax])>+</button>
                </span>
                <button type="button" wire:click="add" wire:loading.attr="aria-busy" wire:target="add" class="{{ $primaryButton }}">{{ __('storefront.sealed.add') }}</button>
            </div>
            @if ($atMax)
                <p class="text-small text-muted">{{ __('storefront.sealed.at_max', ['n' => $cap]) }}</p>
            @endif
            <p class="text-small text-muted">{{ __('storefront.sealed.lock_note', ['minutes' => config('shop.reservation_minutes')]) }}</p>
        @elseif (! $allMine)
            <p role="status" class="text-small font-medium text-muted">{{ __('storefront.sealed.sold_out') }}</p>
        @endif

        @if ($held !== null)
            <p role="status" class="text-small font-medium text-stock">{{ __('storefront.sealed.in_cart', ['n' => $held->qty, 'minutes' => $minutes]) }}</p>
            <a href="{{ route('cart') }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.graded.view_cart') }}</a>
        @endif
    @endif

    @if ($notice !== null)
        <p role="status" class="text-small text-low">{{ $notice }}</p>
    @endif
</div>
