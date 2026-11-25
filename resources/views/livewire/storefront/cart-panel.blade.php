@php
    $lines = $this->lines;
    $held = $this->heldLines;
    $expiresAt = $this->expiresAt;
    $stepperButton = 'size-11 text-card transition-[background-color] duration-160 ease-ui motion-reduce:transition-none';
    $primaryButton = 'inline-flex min-h-11 items-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $cartIcon = new \Illuminate\Support\HtmlString('<svg width="34" height="34" viewBox="0 0 20 20" fill="none"><path d="M3.5 5h13l-1.2 8.4H4.7L3.5 5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><circle cx="7.4" cy="16.4" r="1.2" fill="currentColor"/><circle cx="12.8" cy="16.4" r="1.2" fill="currentColor"/></svg>');
    $insuredCount = $this->cartLines->insuredCount();
    $insuranceSatang = $insuredCount * (int) config('shipping.graded_insurance_satang');
    $anyExpired = $held->isEmpty() && $lines->contains(fn (\App\Models\CartItem $line): bool => ! $this->isGone($line));
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
@endphp

<div class="flex flex-col gap-6">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.cart.title') }}</h1>
        @if ($lines->isNotEmpty())
            <p class="text-small text-faint">{{ __('storefront.cart.lines', ['n' => $lines->count()]) }}</p>
        @endif
    </div>

    <x-auth.status />

    @if ($lines->isEmpty())
        <x-empty-state :title="__('storefront.cart.empty')" :hint="__('storefront.cart.deck_hint')" :icon="$cartIcon">
            <a href="{{ route('deck.import') }}" class="{{ $primaryButton }}">{{ __('storefront.cart.deck') }}</a>
            <a href="{{ route('search') }}" class="{{ $secondaryButton }}">{{ __('storefront.cart.search') }}</a>
        </x-empty-state>
    @else
        @if ($expiresAt !== null)
            <x-lock-banner :expires-at="$expiresAt" />
        @endif

        @if ($anyExpired)
            <p class="flex items-center gap-3 rounded-control border border-low/40 bg-low/10 px-4 py-3 text-small font-medium text-low">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="flex-none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6"/><path d="M10 5.8V10l2.8 1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                {{ __('storefront.cart.lock_ended') }}
            </p>
        @endif

        <ul class="flex flex-col gap-3">
            @foreach ($lines as $line)
                @php
                    $purchasable = $line->purchasable instanceof \App\Contracts\Purchasable ? $line->purchasable : null;
                    $variant = $purchasable instanceof \App\Models\Variant ? $purchasable : null;
                    $presentation = $purchasable?->cartPresentation();
                    $result = $results[$line->id] ?? null;
                    $outcome = $result['outcome'] ?? null;
                    $gone = $this->isGone($line);
                    $live = ! $gone && (bool) $line->reservation?->isLive();
                    $taken = ! $gone && ! $live && $outcome === 'taken';
                    $substitute = $taken && isset($result['substitute']) ? $this->substitutes->get($result['substitute']) : null;
                    $cap = $purchasable === null ? 0 : min($line->qty + $purchasable->holdableQty(), $purchasable->maxPerLine());
                    $atMin = $line->qty <= 1;
                    $atMax = $line->qty >= $cap;
                    $oneOfAKind = $purchasable !== null && $purchasable->maxPerLine() === 1;
                    $name = $presentation?->title;
                    $variantLabel = $variant?->conditionFinishLabel() ?? ($presentation === null ? null : ($presentation->gradeBadge ?? implode(' · ', $presentation->badges)));
                @endphp
                <li wire:key="line-{{ $line->id }}" @class(['flex items-start gap-3 rounded-card border border-line bg-sunken p-3', 'opacity-60' => $gone])>
                    <x-line-art :presentation="$presentation" size="w-12" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            @if ($gone || $presentation?->url === null)
                                <p class="min-w-0 truncate text-card font-medium text-muted">{{ $name }}</p>
                            @else
                                <a href="{{ $presentation->url }}" class="min-w-0 truncate text-card font-medium hover:text-accent">{{ $name }}</a>
                                <x-price :satang="$line->unit_price_satang * $line->qty" class="flex-none text-card font-medium {{ $live ? '' : 'text-faint' }}" />
                            @endif
                        </div>

                        @unless ($gone)
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-faint">
                                <span class="tabular-nums">{{ $presentation->code }}</span>
                                @if ($presentation->language !== null)
                                    <x-language-badge :language="$presentation->language" />
                                @endif
                                @if ($presentation->condition !== null)
                                    <x-condition-badge :condition="$presentation->condition" />
                                @endif
                                @if ($presentation->gradeBadge !== null)
                                    <x-grade-badge :label="$presentation->gradeBadge" />
                                @endif
                                @foreach ($presentation->badges as $badge)
                                    <span>{{ $badge }}</span>
                                @endforeach
                                @if ($line->qty > 1)
                                    <span class="tabular-nums"><x-price :satang="$line->unit_price_satang" /> &times; {{ $line->qty }}</span>
                                @endif
                            </p>
                        @endunless

                        @if ($gone)
                            <p class="mt-2 text-small text-muted">{{ __('storefront.cart.gone') }}</p>
                        @elseif (! $live && ! $taken)
                            <p class="mt-2 text-small font-medium text-low">{{ __('storefront.cart.expired') }}</p>
                        @elseif ($this->isStockShort($line))
                            <p class="mt-2 text-small font-medium text-low">{{ __('storefront.cart.stock_short') }}</p>
                        @endif

                        @if (! $gone && ! $taken && in_array($outcome, ['renewed', 'at_cap', 'failed', 'swapped'], true))
                            <div class="mt-2 flex flex-col gap-1 text-small text-low" role="status">
                                @if (($result['qty'] ?? null) !== null)
                                    <p>{{ __('storefront.cart.qty_reduced', ['n' => $result['qty']['to']]) }}</p>
                                @endif
                                @if (($result['price'] ?? null) !== null)
                                    <p class="tabular-nums">{{ __('storefront.cart.price_changed', ['from' => $format($result['price']['from']), 'to' => $format($result['price']['to'])]) }}</p>
                                @endif
                                @if ($outcome === 'at_cap')
                                    <p>{{ __('storefront.cart.at_cap') }}</p>
                                @endif
                                @if ($outcome === 'failed')
                                    <p>{{ __('storefront.card.add_failed') }}</p>
                                @endif
                                @if ($outcome === 'swapped')
                                    <p class="text-stock">{{ __('storefront.cart.swapped', ['variant' => $variantLabel]) }}</p>
                                @endif
                            </div>
                        @endif

                        @if ($taken)
                            <div class="mt-2 flex items-start gap-3 rounded-control border border-line bg-surface p-3" role="status">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-danger"><path d="M10 2.8 18 16.6H2L10 2.8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 8v3.4M10 13.6v.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <div class="min-w-0 flex-1">
                                    <p class="text-small font-medium text-danger">{{ __('storefront.cart.taken', ['name' => $name, 'variant' => $variantLabel]) }}</p>
                                    @if ($substitute !== null)
                                        @php
                                            $difference = $substitute->price_satang - $line->unit_price_satang;
                                            $offer = ['variant' => $substitute->conditionFinishLabel(), 'price' => $format($substitute->price_satang), 'diff' => $format(abs($difference))];
                                        @endphp
                                        <p class="mt-1 text-small text-muted tabular-nums">{{ match (true) {
                                            $difference < 0 => __('storefront.cart.substitute_cheaper', $offer),
                                            $difference > 0 => __('storefront.cart.substitute_dearer', $offer),
                                            default => __('storefront.cart.substitute_same', $offer),
                                        } }}</p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if ($substitute !== null)
                                            <button type="button" wire:click="swap({{ $line->id }}, {{ $substitute->id }})" wire:loading.attr="aria-busy" wire:target="swap" class="{{ $primaryButton }}">{{ __('storefront.cart.swap', ['variant' => $substitute->conditionFinishLabel()]) }}</button>
                                        @endif
                                        <button type="button" wire:click="remove({{ $line->id }})" class="{{ $secondaryButton }}">{{ __('storefront.cart.remove_line') }}</button>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="mt-2 flex items-center gap-3">
                                @if ($live && $oneOfAKind)
                                    <span class="text-small text-muted">{{ __('storefront.cart.one_of_a_kind') }}</span>
                                @elseif ($live)
                                    {{-- No overflow-hidden here: it would clip the focus ring. A button at its limit is aria-disabled, not disabled, so focus stays on it. --}}
                                    <span class="inline-flex flex-none items-center rounded-control border border-line-strong bg-surface">
                                        <button type="button" wire:click="decrement({{ $line->id }})" @if ($atMin) aria-disabled="true" @endif aria-label="{{ __('storefront.card.decrease') }}" @class([$stepperButton, 'rounded-l-control', 'text-faint' => $atMin, 'text-ink hover:bg-raised' => ! $atMin])>&minus;</button>
                                        <span class="min-w-9 text-center text-body font-medium tabular-nums" aria-live="polite">{{ $line->qty }}</span>
                                        <button type="button" wire:click="increment({{ $line->id }})" @if ($atMax) aria-disabled="true" @endif aria-label="{{ __('storefront.card.increase') }}" @class([$stepperButton, 'rounded-r-control', 'text-faint' => $atMax, 'text-ink hover:bg-raised' => ! $atMax])>+</button>
                                    </span>
                                @elseif (! $gone)
                                    <span class="text-small tabular-nums text-muted">{{ __('storefront.cart.qty_static', ['n' => $line->qty]) }}</span>
                                    <button type="button" wire:click="renew({{ $line->id }})" wire:loading.attr="aria-busy" wire:target="renew" class="{{ $primaryButton }}">{{ __('storefront.cart.renew') }}</button>
                                @endif
                                <button type="button" wire:click="remove({{ $line->id }})" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-tag px-2 text-small text-muted transition-[color] duration-160 ease-ui hover:text-danger motion-reduce:transition-none">{{ __('storefront.cart.remove') }}@if ($name !== null)<span class="sr-only"> {{ $name }}</span>@endif</button>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($held->isNotEmpty())
            <section aria-labelledby="cart-summary-heading" class="rounded-card border border-line bg-sunken p-4">
                <h2 id="cart-summary-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.cart.summary') }}</h2>
                <p class="flex items-baseline justify-between gap-4 text-small text-muted">
                    <span>{{ __('storefront.cart.items_total', ['n' => $held->count()]) }}</span>
                    <x-price :satang="$this->subtotal" class="text-ink" />
                </p>
                @if ($insuredCount > 0)
                    <p class="mt-1 flex items-baseline justify-between gap-4 text-small text-muted">
                        <span>{{ __('storefront.checkout.insurance', ['n' => $insuredCount]) }}</span>
                        <x-price :satang="$insuranceSatang" class="text-ink" />
                    </p>
                @endif
                @if ($lines->count() > $held->count())
                    <p class="mt-1 text-label text-muted">{{ __('storefront.cart.excluded', ['n' => $lines->count() - $held->count()]) }}</p>
                @endif
                <p class="mt-3 flex items-baseline justify-between gap-4 border-t border-line pt-3">
                    <span class="text-card font-medium">{{ __('storefront.cart.total_before_shipping') }}</span>
                    <x-price :satang="$this->subtotal + $insuranceSatang" class="text-h3 font-medium text-accent" />
                </p>
                @if ($this->canCheckout())
                    <a href="{{ route('checkout') }}" class="{{ $primaryButton }} mt-4 w-full justify-center">{{ __('storefront.cart.checkout') }}</a>
                @else
                    <p class="mt-4 text-small text-muted">{{ __('storefront.cart.checkout_blocked') }}</p>
                @endif
            </section>
        @endif
    @endif
</div>
