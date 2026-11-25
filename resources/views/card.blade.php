@php
    $hasFoil = $printing->hasAvailableFoil();
    $shareUrl = route('card.show', ['card' => $card, 'p' => $printing->id]);
@endphp

<x-layouts.storefront :title="$title" :results="true" :bottom-nav="false">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 pb-8 pt-4 lg:gap-12 lg:px-8 lg:pb-16 lg:pt-6">
        <nav aria-label="{{ __('storefront.card.breadcrumb') }}" class="hidden text-small text-faint lg:block">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('home') }}" class="rounded-tag hover:text-ink">{{ __('storefront.nav.home') }}</a></li>
                <li aria-hidden="true">&rsaquo;</li>
                <li><a href="{{ route('game.show', $game) }}" class="rounded-tag hover:text-ink">{{ $game->name_en }}</a></li>
                <li aria-hidden="true">&rsaquo;</li>
                <li><a href="{{ route('set.show', $printing->set) }}" class="rounded-tag hover:text-ink">{{ $printing->set->display_code }} {{ $printing->set->name_en }}</a></li>
                <li aria-hidden="true">&rsaquo;</li>
                <li aria-current="page" class="text-muted">{{ $printing->display_name }}</li>
            </ol>
        </nav>

        <div class="flex flex-col gap-8 lg:grid lg:grid-cols-[20rem_minmax(0,1fr)] lg:grid-rows-[auto_1fr] lg:gap-x-12 lg:gap-y-8">
            <div class="flex flex-col gap-6 lg:row-span-2 lg:col-start-1 lg:row-start-1">
                <span class="relative mx-auto block w-52 lg:w-full">
                    <x-card-art :foil="$hasFoil" size="w-full" radius="rounded-card" />
                    <x-rarity-badge :code="$printing->rarity_code" class="absolute left-3 top-3" />
                </span>

                <section aria-labelledby="info-heading" class="hidden rounded-card border border-line bg-sunken p-4 lg:block">
                    <h2 id="info-heading" class="mb-3 text-label font-medium text-faint">{{ __('storefront.card.info_title') }}</h2>
                    <dl class="flex flex-col gap-2 text-small">
                        @foreach ($info as $label => $value)
                            <div class="flex justify-between gap-4">
                                <dt class="flex-none text-faint">{{ $label }}</dt>
                                <dd class="min-w-0 text-right">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>

            <div class="min-w-0 lg:col-start-2 lg:row-start-1">
                <div class="flex items-start gap-2">
                    <h1 class="min-w-0 flex-1 font-display text-h2 font-medium lg:text-h1">{{ $printing->display_name }}</h1>
                    <div
                        x-data="{ canShare: !!(navigator.share || (navigator.clipboard && navigator.clipboard.writeText)), copied: false }"
                        x-show="canShare"
                        x-cloak
                        class="flex-none"
                    >
                        <button
                            type="button"
                            x-on:click="
                                if (navigator.share) {
                                    navigator.share({ title: @js($printing->display_name), url: @js($shareUrl) }).catch(() => {});
                                } else {
                                    navigator.clipboard.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2500); }).catch(() => {});
                                }
                            "
                            class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line px-3 text-small text-muted transition-[color,border-color] duration-160 ease-ui hover:border-line-strong hover:text-ink motion-reduce:transition-none"
                        >
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 13V3.5M6.5 7 10 3.5 13.5 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 12v4.5h11V12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            {{ __('storefront.card.share') }}
                        </button>
                        <span role="status" class="sr-only" x-text="copied ? @js(__('storefront.card.link_copied')) : ''"></span>
                        <p x-show="copied" x-cloak aria-hidden="true" class="mt-1 text-right text-label text-stock">{{ __('storefront.card.link_copied') }}</p>
                    </div>
                </div>

                <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-small text-muted">
                    <a href="{{ route('set.show', $printing->set) }}" class="inline-flex min-h-11 items-center rounded-tag tabular-nums underline decoration-line-strong underline-offset-4 hover:text-ink">{{ $printing->display_code }}</a>
                    <span aria-hidden="true" class="h-3 w-px bg-line"></span>
                    <span>{{ $game->name_en }}</span>
                    <x-rarity-badge :code="$printing->rarity_code" class="border border-line" />
                    <x-language-badge :language="$printing->language" />
                </p>

                <p class="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    @if ($lowSatang !== null)
                        <span @class(['text-h2 font-medium tabular-nums lg:text-h1', 'text-faint' => ! $inStock])>
                            <x-price :satang="$lowSatang" />@if ($highSatang !== $lowSatang) &ndash; <x-price :satang="$highSatang" />@endif
                        </span>
                    @endif
                    @if ($inStock)
                        <span class="text-small font-medium tabular-nums text-stock">{{ __('storefront.card.stock_total', ['n' => $availableQty]) }}</span>
                    @else
                        <x-stock-indicator :in-stock="false" :qty="0" class="text-small" />
                    @endif
                </p>

                <x-trust-row :has-real-photo="$hasRealPhoto" class="mt-4" />
            </div>

            <livewire:storefront.variant-table :card-id="$card->id" :printing-id="$printing->id" />

            @if ($otherPrintings->isNotEmpty())
                <section aria-labelledby="other-cards-heading" class="min-w-0 lg:col-span-2">
                    <div class="mb-3 flex items-baseline justify-between gap-4">
                        <h2 id="other-cards-heading" class="font-display text-card font-medium lg:text-h3">{{ __('storefront.card.other_cards', ['code' => $printing->set->display_code]) }}</h2>
                    </div>
                    <ul class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 lg:mx-0 lg:grid lg:grid-cols-6 lg:overflow-visible lg:px-0 lg:pb-0">
                        @foreach ($otherPrintings as $other)
                            <li class="w-36 flex-none lg:w-auto">
                                <x-card-tile :printing="$other" class="h-full" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="lg:col-span-2">
                <a href="{{ route('set.show', $printing->set) }}" class="inline-flex min-h-11 items-center text-small text-accent hover:text-ink">{{ __('storefront.home.browse_set') }}</a>
            </p>
        </div>
    </div>
</x-layouts.storefront>
