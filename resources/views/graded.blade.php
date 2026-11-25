@php
    $photos = collect([
        ['key' => 'front', 'column' => 'front_path', 'label' => __('storefront.graded.photo_front')],
        ['key' => 'back', 'column' => 'back_path', 'label' => __('storefront.graded.photo_back')],
        ['key' => 'label', 'column' => 'label_path', 'label' => __('storefront.graded.photo_label')],
    ])->map(fn (array $photo): array => $photo + [
        'url' => $item->photoUrl($photo['column']),
        'alt' => __('storefront.graded.photo_alt', ['side' => $photo['label'], 'name' => $printing->display_name]),
    ])->filter(fn (array $photo): bool => $photo['url'] !== null)->values();
    $subgrades = collect($item->subgrades ?? [])->filter(fn (mixed $value): bool => filled($value));
    $verifyUrl = $item->verifyUrl();
    $sold = $item->status === \App\Enums\GradedStatus::Sold;
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
@endphp

<x-layouts.storefront :title="$title" :results="true" :bottom-nav="false">
    <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <nav aria-label="{{ __('storefront.card.breadcrumb') }}" class="hidden text-small text-faint lg:block">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('home') }}" class="rounded-tag hover:text-ink">{{ __('storefront.nav.home') }}</a></li>
                <li aria-hidden="true">&rsaquo;</li>
                <li><a href="{{ route('card.show', ['card' => $card, 'p' => $printing->id]) }}" class="rounded-tag hover:text-ink">{{ $printing->display_name }}</a></li>
                <li aria-hidden="true">&rsaquo;</li>
                <li aria-current="page" class="text-muted">{{ __('storefront.graded.breadcrumb') }} {{ $item->gradeBadge() }}</li>
            </ol>
        </nav>

        {{-- On a phone the order is: one-line header, photos, then the slab and the buy column. On a desktop the grid puts the
             photos left and the buy column right, and the header is the heading in the right column. --}}
        <div class="flex flex-col gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_26rem] lg:gap-x-12">
            <div class="order-1 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 lg:hidden">
                <x-grade-badge :label="$item->gradeBadge()" />
                @if (filled($item->grade_label))
                    <span class="text-small text-muted">{{ $item->grade_label }}</span>
                @endif
                <h1 class="min-w-0 truncate font-display text-card font-medium">{{ $printing->display_name }}</h1>
            </div>

            {{-- The real photos, the only real images the shop shows. Never card art. A thumb swaps the main photo; the main
                 photo opens the lightbox at the photo it shows. --}}
            <section
                aria-labelledby="graded-photos-heading"
                class="order-2 min-w-0 lg:col-start-1 lg:row-start-1"
                x-data="{
                    current: 0,
                    open: null,
                    zoom: false,
                    labels: @js($photos->pluck('label')->all()),
                    show(index) { this.open = index; this.current = index; this.zoom = false; },
                    step(by) { if (this.open !== null) { this.show((this.open + by + this.labels.length) % this.labels.length); } },
                }"
                x-on:keydown.escape.window="open = null"
                x-on:keydown.arrow-left.window="step(-1)"
                x-on:keydown.arrow-right.window="step(1)"
            >
                <h2 id="graded-photos-heading" class="sr-only">{{ __('storefront.graded.photos') }}</h2>

                @if ($photos->isNotEmpty())
                    <button type="button" x-on:click="show(current)" aria-label="{{ __('storefront.graded.zoom_current') }}" class="mx-auto block aspect-[3/4] max-h-[55vh] w-full rounded-card border border-line bg-sunken p-1 transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none lg:max-h-[calc(100vh-14rem)]">
                        @foreach ($photos as $index => $photo)
                            <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" @if ($index > 0) x-cloak @endif x-show="current === {{ $index }}" class="size-full rounded-tag object-contain">
                        @endforeach
                    </button>
                    <p class="mt-2 text-center text-label text-faint">{{ __('storefront.graded.tap_to_zoom') }}</p>

                    <ul class="mt-3 flex justify-center gap-3 lg:justify-start">
                        @foreach ($photos as $index => $photo)
                            <li>
                                <button
                                    type="button"
                                    x-on:click="current = {{ $index }}"
                                    :aria-current="current === {{ $index }} ? 'true' : null"
                                    :class="current === {{ $index }} ? 'border-accent' : 'border-line'"
                                    aria-label="{{ $photo['label'] }}"
                                    class="block size-20 rounded-control border-2 bg-sunken p-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                >
                                    <img src="{{ $photo['url'] }}" alt="" loading="lazy" class="size-full rounded-tag object-cover">
                                </button>
                                <p class="mt-2 text-center text-label text-faint">{{ $photo['label'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- No transitions, so it is reduced-motion safe. x-trap keeps focus inside and gives it back on close. --}}
                <div x-show="open !== null" x-cloak x-trap.noscroll="open !== null" role="dialog" aria-modal="true" aria-label="{{ __('storefront.graded.photos') }}" class="fixed inset-0 z-50 flex flex-col bg-bg/95">
                    <div class="flex flex-wrap items-center justify-between gap-3 p-3">
                        <p class="text-small font-medium text-ink" aria-live="polite" x-text="open === null ? '' : labels[open] + ' · ' + (open + 1) + '/' + labels.length"></p>
                        <div class="flex items-center gap-2">
                            <button type="button" x-on:click="zoom = ! zoom" class="inline-flex min-h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink" x-text="zoom ? @js(__('storefront.graded.fit')) : @js(__('storefront.graded.full_size'))">{{ __('storefront.graded.full_size') }}</button>
                            <button type="button" x-on:click="open = null" class="inline-flex min-h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink">{{ __('storefront.graded.close') }}</button>
                        </div>
                    </div>
                    <div class="relative flex min-h-0 flex-1 items-center justify-center">
                        <button type="button" x-on:click="step(-1)" aria-label="{{ __('storefront.graded.prev') }}" class="absolute left-2 z-10 inline-flex size-11 items-center justify-center rounded-control border border-line-strong bg-surface text-ink">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="h-full w-full overflow-auto">
                            <div class="flex min-h-full min-w-full items-center justify-center p-2">
                                @foreach ($photos as $index => $photo)
                                    <button type="button" x-show="open === {{ $index }}" x-on:click="zoom = ! zoom" aria-label="{{ __('storefront.graded.toggle_size') }}" class="block">
                                        <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" :class="zoom ? 'max-h-none max-w-none cursor-zoom-out' : 'max-h-[80vh] max-w-full cursor-zoom-in'" class="rounded-card object-contain">
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <button type="button" x-on:click="step(1)" aria-label="{{ __('storefront.graded.next') }}" class="absolute right-2 z-10 inline-flex size-11 items-center justify-center rounded-control border border-line-strong bg-surface text-ink">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </div>
            </section>

            <div class="order-3 flex min-w-0 flex-col gap-5 lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:self-start">
                <div>
                    <h1 class="hidden font-display text-h2 font-medium lg:block lg:text-h1">{{ $printing->display_name }}</h1>
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-2 text-small text-muted lg:mt-2">
                        <span class="tabular-nums">{{ $printing->display_code }}</span>
                        <span aria-hidden="true" class="h-3 w-px bg-line"></span>
                        <span>{{ $game->name_en }}</span>
                        <x-rarity-badge :code="$printing->rarity_code" class="border border-line" />
                        <x-language-badge :language="$printing->language" />
                    </p>
                </div>

                {{-- The slab label: what it is and whether it is real, before what it costs. --}}
                <section aria-label="{{ __('storefront.graded.breadcrumb') }}" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-4">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <x-grade-badge :label="$item->gradeBadge()" :large="true" />
                        @if (filled($item->grade_label))
                            <span class="text-small text-muted">{{ $item->grade_label }}</span>
                        @endif
                    </div>

                    @if (filled($item->cert_number))
                        <p class="flex items-center justify-between gap-4 border-t border-line pt-3 text-small">
                            <span class="flex-none text-faint">{{ __('storefront.graded.cert') }}</span>
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="tabular-nums">{{ $item->cert_number }}</span>
                                @if ($verifyUrl !== null)
                                    <a href="{{ $verifyUrl }}" rel="noopener" target="_blank" class="inline-flex min-h-11 items-center gap-1 text-accent hover:text-ink">
                                        {{ __('storefront.graded.verify', ['grader' => $item->grader->label()]) }}
                                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8 4H4.5v11.5H16V12M11 4h5v5M16 4l-7 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <span class="sr-only">{{ __('storefront.graded.new_tab') }}</span>
                                    </a>
                                @endif
                            </span>
                        </p>
                    @endif

                    @if ($subgrades->isNotEmpty())
                        <div class="border-t border-line pt-3">
                            <h2 class="mb-2 text-label font-medium text-faint">{{ __('storefront.graded.subgrades') }}</h2>
                            <dl class="grid grid-cols-4 gap-2 text-small">
                                @foreach ($subgrades as $name => $value)
                                    <div>
                                        <dt class="text-label text-faint">{{ __('storefront.graded.subgrade_'.$name) }}</dt>
                                        <dd class="tabular-nums text-ink">{{ number_format((float) $value, 1) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                </section>

                <p class="inline-flex min-h-7 items-center self-start rounded-control border border-stock/40 bg-raised px-3 py-1 text-label font-medium text-stock">{{ __('storefront.graded.guarantee') }}</p>

                <livewire:storefront.graded-buy-box :graded-item-id="$item->id" />

                @unless ($sold)
                    <p class="text-small text-muted">{{ __('storefront.graded.insured') }}<span class="block text-label text-faint">{{ __('storefront.graded.insured_amount', ['amount' => $format((int) config('shipping.graded_insurance_satang'))]) }}</span></p>
                @endunless

                @if (filled($item->note_th))
                    <p class="text-small text-muted">{{ $item->note_th }}</p>
                @endif
            </div>
        </div>
    </div>
</x-layouts.storefront>
