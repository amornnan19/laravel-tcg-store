@php
    $selected = $this->selected;
    $quantity = $this->quantity();
    $cap = $this->cap;
    $chart = $this->chart;
    $multiplePrintings = $this->rows->pluck('printing_id')->unique()->count() > 1;
    $selectedLabel = $selected === null ? null : strtoupper($selected->condition->value).' · '.$selected->printing->language->shortCode().' · '.$selected->finish->label();
    $columns = 'lg:grid-cols-[4rem_5.5rem_minmax(0,1fr)_9rem_6rem]';
    $stepperButton = 'size-11 text-card transition-[background-color] duration-160 ease-ui motion-reduce:transition-none';
    $atMin = $quantity <= 1;
    $atMax = $quantity >= $cap;
    $inCart = $this->inCart;
    $minutes = config('shop.reservation_minutes');
    $alerts = $this->alerts;
    $smallButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $watchTarget = $selected === null ? null : ($alerts['price'][$selected->id] ?? null);
    $rowLabel = fn (\App\Models\Variant $variant): string => strtoupper($variant->condition->value).' · '.$variant->printing->language->shortCode().' · '.$variant->finish->label();
    $addButton = 'flex-none items-center justify-center gap-2 bg-accent font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
@endphp

<div class="flex flex-col gap-8 lg:contents">
    <section aria-labelledby="options-heading" class="min-w-0 rounded-sheet border border-line bg-sunken p-4 lg:col-start-2 lg:row-start-2 lg:p-6">
        <h2 id="options-heading" class="mb-4 font-display text-card font-medium lg:text-h3">{{ __('storefront.card.options_title') }}</h2>

        @if ($this->languages->count() > 1)
            <div role="group" aria-label="{{ __('storefront.card.language_group') }}" class="mb-4 flex gap-1 rounded-control border border-line bg-bg p-1">
                @foreach ($this->languages as $language)
                    <button
                        type="button"
                        wire:click="selectLanguage('{{ $language->value }}')"
                        wire:key="tab-{{ $language->value }}"
                        aria-pressed="{{ $language->value === $this->language ? 'true' : 'false' }}"
                        @class([
                            'min-h-11 flex-1 rounded-tag px-4 text-small transition-[color,background-color] duration-160 ease-ui motion-reduce:transition-none lg:flex-none lg:px-6',
                            'bg-raised font-medium text-ink' => $language->value === $this->language,
                            'text-muted hover:text-ink' => $language->value !== $this->language,
                        ])
                    ><span aria-hidden="true">{{ $language->shortCode() }}</span><span class="sr-only">{{ $language->label() }}</span></button>
                @endforeach
            </div>
        @endif

        {{-- One tab stop: the selected row. Arrow keys move the selection, as a radio group does. --}}
        <div
            role="radiogroup"
            aria-label="{{ __('storefront.card.rows_label') }}"
            x-data="{
                move(step) {
                    const items = [...$el.querySelectorAll('[role=radio]:not([disabled])')];
                    const index = items.indexOf(document.activeElement);

                    if (index < 0 || items.length < 2) {
                        return;
                    }

                    const next = items[(index + step + items.length) % items.length];
                    next.focus();
                    next.click();
                },
            }"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-right.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.arrow-left.prevent="move(-1)"
            class="flex flex-col gap-2"
        >
            <div aria-hidden="true" @class(['hidden items-center gap-3 border border-transparent px-3 text-label text-faint lg:grid', $columns])>
                <span>{{ __('storefront.card.col_condition') }}</span>
                <span>{{ __('storefront.card.col_finish') }}</span>
                <span>{{ __('storefront.card.col_note') }}</span>
                <span class="text-right">{{ __('storefront.card.col_price') }}</span>
                <span class="text-right">{{ __('storefront.card.col_stock') }}</span>
            </div>

            @foreach ($this->rows as $row)
                @php
                    $selectable = $row->isAvailable() && $row->price_satang !== null;
                    $isSelected = $selected !== null && $selected->is($row);
                    $restockable = ! $selectable && $row->price_satang !== null;
                @endphp

                @if ($multiplePrintings && ($loop->first || $this->rows[$loop->index - 1]->printing_id !== $row->printing_id))
                    <p wire:key="group-{{ $row->printing_id }}" class="mt-2 flex items-center gap-2 text-small text-muted first:mt-0">
                        <span class="tabular-nums">{{ $row->printing->display_code }}</span>
                        <x-rarity-badge :code="$row->printing->rarity_code" class="border border-line" />
                    </p>
                @endif

                {{-- One bordered unit for a sold-out priced row: the dimmed radio and its own restock footer. No overflow-hidden on it, which would clip the focus ring of the footer's button. --}}
                <div wire:key="row-wrap-{{ $row->id }}" @class(['flex flex-col', 'rounded-control border border-line bg-surface' => $restockable])>
                <button
                    type="button"
                    role="radio"
                    wire:key="row-{{ $row->id }}"
                    wire:click="selectVariant({{ $row->id }})"
                    aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                    tabindex="{{ $isSelected ? 0 : -1 }}"
                    @disabled(! $selectable)
                    @class([
                        'relative grid min-h-14 w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 overflow-hidden px-3 py-2 text-left transition-[border-color,background-color] duration-160 ease-ui motion-reduce:transition-none',
                        $columns,
                        'rounded-control border' => ! $restockable,
                        'rounded-none border-0' => $restockable,
                        'border-line-strong bg-raised ring-1 ring-accent/40 before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-r-tag before:bg-accent' => $isSelected,
                        'border-line bg-surface hover:border-line-strong hover:bg-raised' => ! $isSelected && $selectable,
                        'cursor-not-allowed bg-surface opacity-55' => ! $selectable,
                        'border-line' => ! $selectable && ! $restockable,
                    ])
                >
                    <x-condition-badge :condition="$row->condition" />
                    <span class="min-w-0 lg:contents">
                        <span class="block text-small font-medium">{{ $row->finish->label() }}</span>
                        <span class="mt-1 block truncate text-label text-faint lg:mt-0 lg:text-small">{{ filled($row->note_th) ? $row->note_th : __('storefront.card.no_note') }}</span>
                    </span>
                    <span class="text-right lg:contents">
                        <span @class(['block text-card font-medium lg:text-right', 'text-faint' => ! $selectable])>
                            @if ($row->price_satang !== null)
                                <x-price :satang="$row->price_satang" :compare-at="$row->compare_at_satang" />
                            @else
                                <span class="text-small font-normal">{{ __('storefront.card.no_price') }}</span>
                            @endif
                        </span>
                        <span class="mt-1 block lg:mt-0 lg:text-right">
                            <x-stock-indicator :in-stock="$row->isAvailable()" :qty="$row->availableQty()" class="block" />
                            @if (($inCart[$row->id] ?? 0) > 0)
                                <span class="block text-label tabular-nums text-accent">{{ __('storefront.card.in_cart', ['n' => $inCart[$row->id]]) }}</span>
                            @endif
                            @if (isset($alerts['price'][$row->id]))
                                <span class="block text-label tabular-nums text-accent">{{ __('storefront.alerts.price_watch_marker', ['target' => \App\Support\Money::fromSatang($alerts['price'][$row->id])->format()]) }}</span>
                            @endif
                        </span>
                    </span>
                </button>
                {{-- A restock refusal for a row that is in stock again (a hold expired between render and click) shows under its radio. --}}
                @if (! $restockable && isset($this->restockNotices[$row->id]))
                    <p role="status" class="px-3 pb-2 text-small text-low">{{ $this->restockNotices[$row->id] }}</p>
                @endif
                {{-- Outside the row's radio: a button cannot sit inside a button. Its result renders here, in this row. --}}
                @if ($restockable)
                    @php
                        $restockSet = isset($alerts['restock'][$row->id]);
                        $restockNotice = $this->restockNotices[$row->id] ?? null;
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-line px-3 py-2">
                        <p class="text-label text-muted max-lg:basis-full">{{ __('storefront.alerts.restock_hint'.(\App\Support\LineLogin::isConfigured() ? '' : '_email')) }}</p>
                        <button
                            type="button"
                            wire:key="restock-action-{{ $row->id }}"
                            id="restock-action-{{ $row->id }}"
                            wire:click="{{ $restockSet ? 'cancelRestock' : 'notifyRestock' }}({{ $row->id }})"
                            aria-label="{{ __($restockSet ? 'storefront.alerts.restock_cancel_label' : 'storefront.alerts.notify_restock_label', ['variant' => $rowLabel($row)]) }}"
                            class="{{ $smallButton }} max-lg:w-full"
                        >{{ $restockSet ? __('storefront.alerts.restock_cancel') : __('storefront.alerts.notify_restock') }}</button>
                        <p role="status" @class(['basis-full text-small', 'text-stock' => $restockSet && $restockNotice === null, 'text-low' => $restockNotice !== null, 'hidden' => ! $restockSet && $restockNotice === null])>{{ $restockNotice ?? ($restockSet ? __('storefront.alerts.restock_set') : '') }}</p>
                    </div>
                @endif
                </div>
            @endforeach
        </div>

        @if ($selected !== null)
            <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-3 rounded-control border border-line bg-bg px-3 py-2">
                <span class="min-w-0 flex-1 text-small text-muted">{{ __('storefront.card.quantity') }} <span class="font-medium text-ink">{{ $selectedLabel }}</span></span>
                {{-- No overflow-hidden here: it would clip the focus ring. A button at its limit is aria-disabled, not disabled, so focus stays on it; the actions clamp anyway. --}}
                <span class="inline-flex flex-none items-center rounded-control border border-line-strong bg-surface">
                    <button type="button" wire:click="decrement" @if ($atMin) aria-disabled="true" @endif aria-label="{{ __('storefront.card.decrease') }}" @class([$stepperButton, 'rounded-l-control', 'text-faint' => $atMin, 'text-ink hover:bg-raised' => ! $atMin])>&minus;</button>
                    <span class="min-w-9 text-center text-body font-medium tabular-nums" aria-live="polite">{{ $quantity }}</span>
                    <button type="button" wire:click="increment" @if ($atMax) aria-disabled="true" @endif aria-label="{{ __('storefront.card.increase') }}" @class([$stepperButton, 'rounded-r-control', 'text-faint' => $atMax, 'text-ink hover:bg-raised' => ! $atMax])>+</button>
                </span>
                {{-- On a desktop the total and the add button live here; a phone has the bar below. --}}
                <p class="hidden flex-none items-baseline gap-2 lg:flex">
                    <span class="text-small text-faint">{{ __('storefront.card.total') }}</span>
                    <x-price :satang="$selected->price_satang * $quantity" class="text-h3 font-medium text-accent" />
                </p>
                <button type="button" wire:click="addToCart" wire:loading.attr="aria-busy" wire:target="addToCart" @class([$addButton, 'hidden min-h-11 rounded-control px-5 text-body lg:inline-flex'])>
                    @if ($this->added)
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m4.5 10.5 3.6 3.6 7.4-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('storefront.card.added') }}
                    @else
                        {{ __('storefront.card.add') }}
                    @endif
                </button>
            </div>
            <div class="mt-3 flex flex-col gap-2">
                @if ($watchTarget !== null)
                    <p class="flex flex-wrap items-center gap-3 text-small">
                        <span class="text-stock">{{ __('storefront.alerts.price_watch_set', ['variant' => $selectedLabel, 'target' => \App\Support\Money::fromSatang($watchTarget)->format()]) }}</span>
                        <button type="button" wire:click="cancelPriceWatch({{ $selected->id }})" class="{{ $smallButton }}">{{ __('storefront.alerts.price_watch_cancel') }}</button>
                    </p>
                @elseif ($this->watching)
                    @php
                        $example = max(1, intdiv(intdiv($selected->price_satang * 9, 10), 100));
                        $priceError = $this->priceWatchError;
                    @endphp
                    <form wire:submit="setPriceWatch" class="flex flex-col gap-2">
                        <div>
                            <label for="price-target" class="mb-1 block text-small text-muted">{{ __('storefront.alerts.price_target_label', ['variant' => $selectedLabel]) }}</label>
                            <input
                                id="price-target"
                                type="text"
                                inputmode="decimal"
                                wire:model="priceTarget"
                                data-text-input
                                autocomplete="off"
                                placeholder="{{ __('storefront.alerts.price_target_placeholder', ['example' => $example]) }}"
                                aria-describedby="{{ $priceError !== null ? 'price-target-error price-target-hint' : 'price-target-hint' }}"
                                @if ($priceError !== null) aria-invalid="true" @endif
                                @class(['block min-h-11 w-full rounded-control border bg-surface px-3 text-body text-ink tabular-nums placeholder:text-faint aria-invalid:focus-visible:outline-danger', 'border-danger' => $priceError !== null, 'border-line-strong' => $priceError === null])
                            >
                        </div>
                        @if ($priceError !== null)
                            <p id="price-target-error" role="alert" class="basis-full text-small text-danger">{{ $priceError }}</p>
                        @endif
                        <p id="price-target-hint" class="basis-full text-label text-faint">{{ __('storefront.alerts.price_target_hint') }}</p>
                        <div class="flex gap-3 max-lg:w-full max-lg:*:flex-1">
                            <button type="submit" class="{{ $smallButton }}">{{ __('storefront.alerts.price_target_submit') }}</button>
                            <button type="button" wire:click="closePriceWatch" class="{{ $smallButton }}">{{ __('storefront.alerts.price_target_close') }}</button>
                        </div>
                    </form>
                @else
                    <div>
                        <button type="button" wire:click="openPriceWatch" class="{{ $smallButton }}">{{ __('storefront.alerts.price_watch') }}</button>
                    </div>
                @endif
            </div>
            {{-- The one live region of the table: the add result and the cap sentence are announced from here. --}}
            <p role="status" class="mt-2 text-small">
                @if ($this->notice !== null)
                    <span @class(['block', 'text-stock' => $this->noticeOk, 'text-low' => ! $this->noticeOk])>
                        {{ $this->notice }}
                        @if ($this->noticeOk)
                            <a href="{{ route('cart') }}" class="ml-2 inline-flex min-h-11 items-center rounded-tag text-accent hover:text-ink">{{ __('storefront.card.view_cart') }}</a>
                        @endif
                    </span>
                @endif
                <span @class(['block', 'text-low' => $atMax, 'text-faint' => ! $atMax])>{{ $atMax ? __('storefront.card.cap_reached', ['n' => $cap, 'minutes' => $minutes]) : __('storefront.card.cap', ['n' => $cap, 'minutes' => $minutes]) }}</span>
            </p>
        @else
            <p role="status" class="mt-4 text-small text-muted">
                @if ($this->notice !== null)
                    <span @class(['block', 'text-stock' => $this->noticeOk, 'text-low' => ! $this->noticeOk])>
                        {{ $this->notice }}
                        @if ($this->noticeOk)
                            <a href="{{ route('cart') }}" class="ml-2 inline-flex min-h-11 items-center rounded-tag text-accent hover:text-ink">{{ __('storefront.card.view_cart') }}</a>
                        @endif
                    </span>
                @endif
                <span class="block">{{ __('storefront.card.none_available') }}</span>
            </p>
        @endif
    </section>

    @if ($selected !== null)
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-sunken px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 lg:hidden">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-label text-faint">{{ __('storefront.card.selected_line', ['variant' => $selectedLabel, 'qty' => $quantity]) }}</p>
                    <p class="mt-1 flex items-baseline gap-2">
                        <span class="text-label text-faint">{{ __('storefront.card.total') }}</span>
                        <x-price :satang="$selected->price_satang * $quantity" class="text-h3 font-medium text-accent" />
                    </p>
                </div>
                <button type="button" wire:click="addToCart" wire:loading.attr="aria-busy" wire:target="addToCart" @class([$addButton, 'inline-flex min-h-13 rounded-card px-5 text-card'])>
                    @if ($this->added)
                        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m4.5 10.5 3.6 3.6 7.4-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ __('storefront.card.added') }}
                    @else
                        {{ __('storefront.card.add') }}
                    @endif
                </button>
            </div>
        </div>
    @endif

    @if ($chart !== null)
        @php
            $change = $chart['change_percent'];
            $viewWidth = \App\Support\PriceChart::WIDTH;
            $viewHeight = \App\Support\PriceChart::HEIGHT;
            $lastIsToday = $chart['last_date']->isToday();
            $wholeBaht = fn (int $satang): string => \App\Support\Money::fromSatang(intdiv($satang + 50, 100) * 100)->format();
        @endphp
        <section aria-labelledby="chart-heading" class="min-w-0 lg:col-span-2">
            <div class="mb-3 flex items-baseline justify-between gap-4">
                <h2 id="chart-heading" class="font-display text-card font-medium lg:text-h3">{{ __('storefront.card.chart_title') }} <span class="text-small font-normal text-faint">· {{ $selectedLabel }}</span></h2>
                <span class="flex-none text-label text-faint">{{ __(config('shop.sample_price_history') ? 'storefront.card.chart_caption_sample' : 'storefront.card.chart_caption', ['n' => $chart['covered_days']]) }}</span>
            </div>
            <div class="rounded-card border border-line bg-sunken p-4 lg:p-6">
                <p class="mb-3 flex flex-wrap items-baseline gap-x-3">
                    <x-price :satang="$chart['last_satang']" class="text-h3 font-medium" />
                    @if ($change !== null)
                        <span @class(['text-label tabular-nums', 'text-stock' => $change >= 0, 'text-danger' => $change < 0])>{{ __('storefront.card.change', ['percent' => ($change >= 0 ? '+' : '−').number_format(abs($change), 1).'%']) }}</span>
                    @endif
                </p>
                <div class="relative">
                    <svg
                        role="img"
                        aria-label="{{ __('storefront.card.chart_aria', ['days' => $chart['covered_days'], 'from' => \App\Support\Money::fromSatang($chart['first_satang'])->format(), 'to' => \App\Support\Money::fromSatang($chart['last_satang'])->format()]) }}"
                        viewBox="0 0 {{ $viewWidth }} {{ $viewHeight }}"
                        preserveAspectRatio="none"
                        class="block h-24 w-full overflow-visible lg:h-56"
                    >
                        @foreach ($chart['grid'] as $y)
                            <line x1="0" y1="{{ $y }}" x2="{{ $viewWidth }}" y2="{{ $y }}" class="stroke-line" stroke-width="1" vector-effect="non-scaling-stroke"/>
                        @endforeach
                        <path d="{{ $chart['path'] }}" fill="none" class="stroke-accent" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                    </svg>
                    <span aria-hidden="true" class="absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-accent" style="left: {{ round($chart['end_x'] / $viewWidth * 100, 2) }}%; top: {{ round($chart['end_y'] / $viewHeight * 100, 2) }}%"></span>
                </div>
                <p class="mt-2 flex justify-between text-label tabular-nums text-faint">
                    <span>{{ $chart['first_date']->isToday() ? __('storefront.card.today') : __('storefront.card.days_ago', ['n' => $chart['covered_days'] - 1]) }}</span>
                    <span>{{ $lastIsToday ? __('storefront.card.today') : $chart['last_date']->locale('th')->translatedFormat('j M') }}</span>
                </p>
                <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-line pt-3 text-label text-faint">
                    <span>{{ __('storefront.card.chart_low') }} <span class="tabular-nums text-muted">{{ $wholeBaht($chart['low_satang']) }}</span></span>
                    <span>{{ __('storefront.card.chart_high') }} <span class="tabular-nums text-muted">{{ $wholeBaht($chart['high_satang']) }}</span></span>
                </p>
            </div>
        </section>
    @endif
</div>
