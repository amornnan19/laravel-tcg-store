@php
    $optionNumber = 0;
    $hasPanel = $result !== null || $recent !== [];
    $cardTotal = $result === null ? 0 : max($result->printingTotal, $result->printings->count());
    $hasResults = $result !== null && ($jumpSets->isNotEmpty() || $result->printings->isNotEmpty() || $result->sets->isNotEmpty());
@endphp

<div
    x-data="searchBox"
    x-effect="document.body.classList.toggle('max-lg:overflow-hidden', open)"
    x-on:keydown.window="focusOnSlash($event)"
    x-on:open-search.window="openBox(); $nextTick(() => $refs.input.focus())"
    x-on:click.outside="close()"
    x-trap="open && !wide"
    x-on:keydown.escape.prevent="close(); $refs.input.blur()"
    x-bind:class="open ? 'max-lg:fixed max-lg:inset-0 max-lg:z-50 max-lg:flex max-lg:flex-col max-lg:gap-3 max-lg:bg-bg max-lg:p-4' : ''"
    class="relative min-w-0 basis-full lg:basis-0 lg:flex-1"
>
    <div class="flex items-center gap-3">
        <button
            type="button"
            x-show="open"
            x-cloak
            x-on:click="close()"
            aria-label="{{ __('storefront.search.back') }}"
            class="flex size-11 flex-none items-center justify-center rounded-control text-ink transition-[color,background-color,border-color] duration-160 ease-ui hover:bg-surface motion-reduce:transition-none lg:hidden"
        >
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12.5 4 6.5 10l6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>

        <div
            x-bind:class="open ? 'border-accent' : 'border-line-strong has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent'"
            class="flex h-12 min-w-0 flex-1 items-center gap-3 rounded-control border bg-surface px-4 transition-[color,background-color,border-color] duration-160 ease-ui motion-reduce:transition-none"
        >
            <svg x-bind:class="open ? 'text-accent' : 'text-faint'" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" class="flex-none transition-[color] duration-160 ease-ui motion-reduce:transition-none"><circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.5"/><path d="M10.6 10.6 14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>

            <label for="search-input" class="sr-only">{{ __('storefront.search.label') }}</label>
            <input
                id="search-input"
                x-ref="input"
                type="search"
                wire:model.live.debounce.150ms="query"
                maxlength="{{ \App\Livewire\Storefront\SearchBox::MAX_QUERY_LENGTH }}"
                autocomplete="off"
                enterkeyhint="search"
                data-search-url="{{ route('search') }}"
                placeholder="{{ __('storefront.search.placeholder_narrow') }}"
                data-narrow="{{ __('storefront.search.placeholder_narrow') }}"
                data-wide="{{ __('storefront.search.placeholder_wide') }}"
                x-bind:placeholder="wide ? $el.dataset.wide : $el.dataset.narrow"
                role="combobox"
                aria-autocomplete="list"
                @if ($hasResults) aria-controls="search-listbox" @endif
                x-bind:aria-expanded="open && {{ $hasPanel ? 'true' : 'false' }}"
                x-bind:aria-activedescendant="active >= 0 ? options()[active]?.id : null"
                x-on:focus="openBox()"
                x-on:input="active = -1"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter="openActive($event)"
                class="min-w-0 flex-1 bg-transparent text-body text-ink placeholder:text-faint focus:outline-none [&::-webkit-search-cancel-button]:hidden"
            >

            @if ($query !== '')
                <button
                    type="button"
                    wire:click="clear"
                    x-on:click="$refs.input.focus()"
                    aria-label="{{ __('storefront.search.clear') }}"
                    class="-mr-3 flex size-11 flex-none items-center justify-center rounded-control text-faint transition-[color,background-color,border-color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none"
                >
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="m2 2 8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </button>
            @endif

            <span aria-hidden="true" class="hidden flex-none rounded-tag border border-line px-2 py-1 text-label text-faint lg:inline-flex">/</span>
        </div>
    </div>

    <div aria-live="polite" class="sr-only">
        @if ($result !== null)
            {{ $hasResults ? __('storefront.search.announce_results', ['n' => $cardTotal]) : __('storefront.search.no_results', ['query' => $query]) }}
        @endif
    </div>

    @if ($hasPanel)
        <div
            x-ref="panel"
            x-show="open"
            x-cloak
            x-on:click="follow($event)"
            x-on:mousemove="hover($event)"
            class="min-h-0 flex-1 overflow-y-auto max-lg:-mx-4 lg:absolute lg:inset-x-0 lg:top-full lg:mt-2 lg:max-h-[calc(100dvh-8rem)] lg:max-w-2xl lg:flex-none lg:rounded-card lg:border lg:border-line lg:bg-surface"
        >
            @if ($result?->limited)
                <p role="status" class="border-b border-line px-4 py-3 text-small text-low">{{ __('storefront.search.limited') }}</p>
            @endif

            @if ($result !== null && ! $hasResults)
                <div class="px-4 py-6">
                    <p class="text-small font-medium text-ink">{{ __('storefront.search.no_results', ['query' => $query]) }}</p>
                    <p class="mt-1 text-label text-muted">{{ __('storefront.search.no_results_hint') }}</p>
                </div>
            @endif

            @if ($hasResults)
                <div id="search-listbox" role="listbox" data-query="{{ $query }}" @if ($enterUrl !== null) data-enter="{{ $enterUrl }}" @endif aria-label="{{ __('storefront.search.results') }}">
                    @foreach ($jumpSets as $set)
                        <a
                            role="option"
                            aria-selected="false"
                            data-option
                            id="search-option-{{ $optionNumber++ }}"
                            href="{{ route('set.show', $set) }}"
                            class="flex min-h-16 items-center gap-3 border-b border-line bg-accent/7 px-4 py-2 text-ink transition-[color,background-color,border-color] duration-160 ease-ui hover:bg-accent/15 aria-selected:outline-2 aria-selected:-outline-offset-2 aria-selected:outline-accent motion-reduce:transition-none"
                        >
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="flex-none text-accent"><path d="M3 10h14M12 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span class="min-w-0 flex-1">
                                <span class="block text-body font-medium">{{ __('storefront.search.jump_set', ['code' => $set->code]) }}</span>
                                <span class="mt-1 block text-small text-faint">{{ __('storefront.search.jump_set_hint') }}</span>
                            </span>
                        </a>
                    @endforeach

                    @if ($result->printings->isNotEmpty())
                        <p class="px-4 pb-2 pt-4 text-label font-medium text-faint">{{ __('storefront.search.group_cards', ['n' => $cardTotal]) }}</p>

                        @foreach ($result->printings as $printing)
                            @php
                                $stock = $printing->stockSummary();
                                $name = match ($printing->language) {
                                    \App\Enums\Language::Ja => $printing->card->name_ja,
                                    \App\Enums\Language::Th => $printing->card->name_th,
                                    default => null,
                                } ?: $printing->card->name_en;
                                $rarityColour = match ($printing->rarity_code) {
                                    'u' => 'text-rarity-u',
                                    'r' => 'text-rarity-r',
                                    'rr' => 'text-rarity-rr',
                                    'sar' => 'text-rarity-sar',
                                    'sec' => 'text-rarity-sec',
                                    default => 'text-rarity-c',
                                };
                                $languageCode = $printing->language === \App\Enums\Language::Ja ? 'JP' : strtoupper($printing->language->value);
                                $priceColour = match (true) {
                                    ! $stock['in_stock'] => 'text-faint',
                                    in_array($printing->rarity_code, ['sar', 'sec'], true) => 'text-accent',
                                    default => '',
                                };
                            @endphp
                            <a
                                role="option"
                                aria-selected="false"
                                data-option
                                id="search-option-{{ $optionNumber++ }}"
                                href="{{ route('card.show', $printing->card) }}"
                                @class([
                                    'flex items-center gap-3 px-4 py-2 text-ink transition-[color,background-color,border-color] duration-160 ease-ui hover:bg-sunken aria-selected:bg-raised aria-selected:outline-2 aria-selected:-outline-offset-2 aria-selected:outline-accent motion-reduce:transition-none',
                                    'opacity-55' => ! $stock['in_stock'],
                                ])
                            >
                                <x-card-art size="w-9" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-body font-medium">{{ $name }}</span>
                                    <span class="mt-1 block truncate text-label text-faint">{{ $printing->display_code }} · <span class="{{ $rarityColour }}">{{ strtoupper($printing->rarity_code) }}</span> · {{ $languageCode }}</span>
                                </span>
                                <span class="flex-none text-right">
                                    @if (($stock['from_satang'] ?? $stock['from_satang_any']) !== null)
                                        <x-price :satang="$stock['from_satang'] ?? $stock['from_satang_any']" :class="trim('block text-body font-medium '.$priceColour)" />
                                    @endif
                                    @if (! $stock['in_stock'])
                                        <span class="mt-1 block text-label text-faint">{{ __('storefront.search.out_of_stock') }}</span>
                                    @elseif ($stock['available_qty'] <= 2)
                                        <span class="mt-1 block text-label tabular-nums text-low">{{ __('storefront.search.low_stock', ['n' => $stock['available_qty']]) }}</span>
                                    @else
                                        <span class="mt-1 block text-label tabular-nums text-stock">{{ __('storefront.search.in_stock', ['n' => $stock['available_qty'] > 40 ? '40+' : $stock['available_qty']]) }}</span>
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    @endif

                    @if ($result->sets->isNotEmpty())
                        <div class="mx-4 my-2 h-px bg-line"></div>
                        <p class="px-4 pb-2 pt-3 text-label font-medium text-faint">{{ __('storefront.search.group_sets', ['n' => $result->sets->count()]) }}</p>

                        @foreach ($result->sets as $set)
                            <a
                                role="option"
                                aria-selected="false"
                                data-option
                                id="search-option-{{ $optionNumber++ }}"
                                href="{{ route('set.show', $set) }}"
                                class="flex min-h-14 items-center gap-3 px-4 py-2 text-ink transition-[color,background-color,border-color] duration-160 ease-ui hover:bg-sunken aria-selected:bg-raised aria-selected:outline-2 aria-selected:-outline-offset-2 aria-selected:outline-accent motion-reduce:transition-none"
                            >
                                <span class="min-w-0 flex-1 truncate text-body font-medium">{{ $set->code }} — {{ $set->name_en }}</span>
                                <span class="flex-none text-label tabular-nums text-faint">{{ __('storefront.search.set_cards', ['n' => $set->card_count]) }}</span>
                            </a>
                        @endforeach
                    @endif
                </div>
            @endif

            @if ($recent !== [])
                <p class="px-4 pb-2 pt-4 text-label font-medium text-faint">{{ __('storefront.search.recent') }}</p>
                <div class="flex flex-wrap gap-2 px-4 pb-4">
                    @foreach ($recent as $term)
                        <button
                            type="button"
                            wire:key="recent-{{ $loop->index }}"
                            wire:click="useRecent({{ $loop->index }})"
                            x-on:click="$refs.input.focus()"
                            class="inline-flex min-h-11 items-center rounded-full border border-line-strong bg-surface px-3 text-small text-muted transition-[color,background-color,border-color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none"
                        >{{ $term }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
