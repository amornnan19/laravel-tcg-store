@php
    $rarityTone = [
        'c' => 'border-rarity-c text-rarity-c bg-surface hover:bg-raised',
        'u' => 'border-rarity-u text-rarity-u bg-surface hover:bg-raised',
        'r' => 'border-rarity-r text-rarity-r bg-surface hover:bg-raised',
        'rr' => 'border-rarity-rr text-rarity-rr bg-surface hover:bg-raised',
        'sar' => 'border-rarity-sar text-rarity-sar bg-surface hover:bg-raised',
        'sec' => 'border-rarity-sec text-rarity-sec bg-surface hover:bg-raised',
    ];
    $flagLabels = ['stock' => 'in_stock', 'foil' => 'foil', 'photo' => 'photo'];
    $pick = fn (string $group, string $value): string => "\$parent.toggle('{$group}', '".addslashes($value)."')";
    $canClear = $panel['active'] > 0;
@endphp

<aside
    x-data="filterSheet"
    data-keys="{{ json_encode($panel['keys']) }}"
    x-on:open-filters.window="show()"
    x-on:keydown.escape.window="cancel()"
    x-effect="document.body.classList.toggle('max-lg:overflow-hidden', open)"
    x-bind:class="open ? 'max-lg:fixed max-lg:inset-0 max-lg:z-50' : 'max-lg:hidden'"
    class="lg:block lg:w-64 lg:flex-none xl:w-72"
>
    <div x-show="open" x-cloak x-on:click="cancel()" aria-hidden="true" class="absolute inset-0 bg-bg/80 lg:hidden"></div>

    <div
        x-ref="dialog"
        tabindex="-1"
        x-trap.noautofocus="open && !wide"
        x-bind:role="open && !wide ? 'dialog' : null"
        x-bind:aria-modal="open && !wide ? 'true' : null"
        aria-label="{{ __('storefront.results.filters') }}"
        class="focus:outline-none max-lg:absolute max-lg:inset-x-0 max-lg:bottom-0 max-lg:flex max-lg:max-h-[90dvh] max-lg:flex-col max-lg:rounded-t-sheet max-lg:border-t max-lg:border-line-strong max-lg:bg-sunken"
    >
        <div aria-hidden="true" class="flex justify-center pt-3 lg:hidden"><span class="h-1 w-11 rounded-full bg-line-strong"></span></div>

        <div class="flex items-center gap-3 border-b border-line px-4 pb-2 pt-2 lg:px-0 lg:pt-0">
            <h2 class="flex-1 font-display text-card font-medium">{{ __('storefront.results.filters') }}</h2>
            @if ($canClear)
                <button type="button" wire:click="$parent.clearAll" class="inline-flex min-h-11 items-center px-3 text-small text-accent transition-[color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none">{{ __('storefront.results.clear_all') }}</button>
            @endif
        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-4 py-4 lg:overflow-visible lg:px-0">
            @foreach ($panel['sections'] as $section)
                <fieldset wire:key="section-{{ $section['key'] }}" class="min-w-0">
                    <legend class="mb-2 flex w-full items-baseline justify-between text-small font-medium text-ink">
                        {{ $section['label'] }}
                        @if ($section['style'] === 'list')
                            <span class="text-small font-normal tabular-nums text-faint">{{ __('storefront.results.set_total', ['n' => count($section['options'])]) }}</span>
                        @endif
                    </legend>

                    @if ($section['style'] === 'list')
                        <div x-data="{ q: '', none() { return this.q !== '' && ! Array.from(this.$root.querySelectorAll('[data-label]')).some((row) => row.dataset.label.includes(this.q.trim().toLowerCase())) } }">
                            <label class="flex h-11 items-center gap-2 rounded-control border border-line-strong bg-surface px-3 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" class="flex-none text-faint"><circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.5"/><path d="M10.6 10.6 14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                <span class="sr-only">{{ __('storefront.results.set_search') }}</span>
                                <input type="search" x-model="q" placeholder="{{ __('storefront.results.set_search') }}" class="min-w-0 flex-1 bg-transparent text-small text-ink placeholder:text-faint focus:outline-none [&::-webkit-search-cancel-button]:hidden">
                            </label>
                            <ul class="mt-2 max-h-64 overflow-y-auto">
                                @foreach ($section['options'] as $option)
                                    <li wire:key="set-{{ $option['value'] }}" data-label="{{ mb_strtolower($option['label']) }}" x-show="q === '' || $el.dataset.label.includes(q.trim().toLowerCase())">
                                        <label class="flex min-h-11 cursor-pointer items-center gap-3 text-small {{ $option['active'] ? 'text-ink' : 'text-muted' }}">
                                            <span class="relative flex size-5 flex-none items-center justify-center">
                                                <input type="checkbox" @checked($option['active']) wire:change="{{ $pick('set', $option['value']) }}" class="peer size-5 cursor-pointer appearance-none rounded-tag border border-line-strong bg-surface checked:border-accent checked:bg-accent">
                                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="pointer-events-none absolute hidden text-accent-ink peer-checked:block"><path d="m2.5 6.2 2.4 2.4 4.6-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1">{{ $option['label'] }}</span>
                                            @if ($option['count'] !== null)
                                                <span class="flex-none text-small tabular-nums text-faint">{{ number_format($option['count']) }}</span>
                                            @endif
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            <p x-show="none()" x-cloak class="py-2 text-small text-faint">{{ __('storefront.results.set_none') }}</p>
                        </div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($section['options'] as $option)
                                <x-filter-chip
                                    :active="$option['active']"
                                    :tone="$section['key'] === 'rarity' ? ($rarityTone[$option['value']] ?? null) : null"
                                    wire:key="option-{{ $section['key'] }}-{{ $option['value'] }}"
                                    wire:click="{{ $pick($section['key'], $option['value']) }}"
                                >{{ $option['label'] }}@if ($option['count'] !== null) <span @class(['text-small tabular-nums', 'opacity-70' => $option['active'], 'text-faint' => ! $option['active']])>{{ number_format($option['count']) }}</span>@endif</x-filter-chip>
                            @endforeach
                        </div>
                    @endif
                </fieldset>
            @endforeach

            @if ($panel['price'] !== null)
                <fieldset class="min-w-0" wire:key="price-{{ $panel['price']['min'] }}-{{ $panel['price']['max'] }}">
                    <legend class="mb-2 text-small font-medium text-ink">{{ __('storefront.results.group.price') }}</legend>
                    <div class="flex items-center gap-2">
                        @foreach (['min', 'max'] as $bound)
                            <label class="min-w-0 flex-1">
                                <span class="sr-only">{{ __('storefront.results.price_'.$bound) }}</span>
                                <input
                                    type="number"
                                    inputmode="numeric"
                                    min="0"
                                    value="{{ $panel['price'][$bound] }}"
                                    placeholder="{{ __('storefront.results.price_'.$bound) }}"
                                    wire:change="$parent.setPrice('{{ $bound }}', $event.target.value)"
                                    class="h-11 w-full rounded-control border border-line-strong bg-surface px-3 text-small tabular-nums text-ink placeholder:text-faint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                >
                            </label>
                            @if ($bound === 'min')
                                <span aria-hidden="true" class="text-faint">–</span>
                            @endif
                        @endforeach
                    </div>
                </fieldset>
            @endif

            <div class="flex flex-col">
                @foreach ($panel['flags'] as $group => $on)
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $on ? 'true' : 'false' }}"
                        wire:key="flag-{{ $group }}"
                        wire:click="$parent.toggleFlag('{{ $group }}')"
                        class="flex min-h-12 w-full items-center gap-3 rounded-control text-left text-small text-ink"
                    >
                        <span class="flex-1">{{ __('storefront.results.toggle.'.$flagLabels[$group]) }}</span>
                        <span @class(['relative h-8 w-12 flex-none rounded-full transition-[background-color] duration-160 ease-ui motion-reduce:transition-none', 'bg-accent' => $on, 'bg-line' => ! $on])>
                            <span @class(['absolute top-1 size-6 rounded-full transition-[left] duration-160 ease-ui motion-reduce:transition-none', 'left-5 bg-accent-ink' => $on, 'left-1 bg-faint' => ! $on])></span>
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3 border-t border-line bg-sunken px-4 pb-5 pt-3 lg:hidden">
            <button type="button" x-on:click="cancel()" class="inline-flex min-h-12 flex-none items-center rounded-control border border-line-strong bg-raised px-5 text-body text-ink">{{ __('storefront.results.cancel') }}</button>
            <button type="button" x-on:click="confirm()" class="inline-flex min-h-12 flex-1 items-center justify-center rounded-control bg-accent px-4 text-body font-medium tabular-nums text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none">{{ __('storefront.results.apply', ['n' => number_format($panel['count'])]) }}</button>
        </div>
    </div>
</aside>
