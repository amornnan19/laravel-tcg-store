@php
    $released = $set->released_on ? $set->released_on->locale('th')->translatedFormat('j M').' '.($set->released_on->year + 543) : null;
    $gridClasses = 'grid grid-cols-2 gap-3 min-[480px]:grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8';
@endphp

<div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 pb-24 pt-4 lg:gap-8 lg:px-8 lg:pb-16 lg:pt-6">
    <nav aria-label="{{ __('storefront.card.breadcrumb') }}" class="hidden text-small text-faint lg:block">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('home') }}" class="rounded-tag hover:text-ink">{{ __('storefront.nav.home') }}</a></li>
            <li aria-hidden="true">&rsaquo;</li>
            <li><a href="{{ route('game.show', $set->game) }}" class="rounded-tag hover:text-ink">{{ $set->game->name_en }}</a></li>
            <li aria-hidden="true">&rsaquo;</li>
            <li aria-current="page" class="text-muted">{{ $set->display_code }} {{ $set->name_en }}</li>
        </ol>
    </nav>

    <header>
        <p class="text-label font-medium text-accent">{{ __('storefront.set.eyebrow') }}</p>
        <h1 class="mt-2 font-display text-h2 font-medium lg:text-h1">{{ $set->display_code }} — {{ $set->name_en }}</h1>
        <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-small tabular-nums text-muted">
            <a href="{{ route('game.show', $set->game) }}" class="rounded-tag hover:text-ink">{{ $set->game->name_en }}</a>
            <span aria-hidden="true" class="h-3.5 w-px bg-line-strong"></span>
            <x-set-counts :set="$set" />
            @if ($released !== null)
                <span aria-hidden="true" class="h-3.5 w-px bg-line-strong"></span>
                <span>{{ __('storefront.home.released', ['date' => $released]) }}</span>
            @endif
        </p>
    </header>

    <div class="flex flex-col gap-3">
        <label class="flex min-h-12 items-center gap-3 rounded-control border border-line-strong bg-surface px-4 text-muted has-focus-visible:border-accent lg:max-w-md">
            <svg width="17" height="17" viewBox="0 0 16 16" fill="none" aria-hidden="true" class="flex-none"><circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.5"/><path d="M10.6 10.6 14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <span class="sr-only">{{ __('storefront.set.search_label') }}</span>
            <input
                type="search"
                wire:model.live.debounce.250ms="query"
                placeholder="{{ __('storefront.set.search_placeholder') }}"
                maxlength="100"
                autocomplete="off"
                class="min-w-0 flex-1 bg-transparent text-body text-ink placeholder:text-faint focus:outline-none"
            >
        </label>

        @if ($chips !== [])
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-label font-medium text-faint">{{ __('storefront.set.jump_to') }}</span>
                <x-filter-chip :active="$activeRange === ''" wire:click="selectRange('')">{{ __('storefront.set.all') }}</x-filter-chip>
                @foreach ($chips as $chip)
                    <x-filter-chip :active="$activeRange === $chip['key']" wire:click="selectRange('{{ $chip['key'] }}')" wire:key="range-{{ $chip['key'] }}">{{ $chip['label'] }}</x-filter-chip>
                @endforeach
            </div>
        @endif

        <label class="flex min-h-11 w-fit cursor-pointer items-center gap-3 text-small text-muted">
            <span class="relative flex size-5 flex-none items-center justify-center">
                <input type="checkbox" wire:model.live="inStockOnly" class="peer size-5 cursor-pointer appearance-none rounded-tag border border-line-strong bg-surface checked:border-accent checked:bg-accent">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="pointer-events-none absolute hidden text-accent-ink peer-checked:block"><path d="m2.5 6.2 2.4 2.4 4.6-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            {{ __('storefront.set.in_stock_only') }}
        </label>
    </div>

    <p aria-live="polite" class="sr-only">{{ __('storefront.results.announce', ['n' => $total]) }}</p>

    <section aria-label="{{ __('storefront.set.grid_label') }}" wire:loading.class="opacity-60" wire:target="query,range,inStockOnly,selectRange,clearFilters">
        @if ($printings->isEmpty())
            <x-empty-state :title="__('storefront.set.empty')" :hint="$isFiltered ? null : __('storefront.set.empty_hint')">
                @if ($isFiltered)
                    <button type="button" wire:click="clearFilters" class="inline-flex min-h-11 items-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none">{{ __('storefront.set.clear') }}</button>
                @endif
            </x-empty-state>
        @else
            <div class="{{ $gridClasses }}">
                @foreach ($printings as $printing)
                    <x-set-tile :printing="$printing" :show-language="$showLanguage" wire:key="set-tile-{{ $printing->id }}" />
                @endforeach
            </div>
        @endif
    </section>

    <div wire:loading.delay.grid wire:target="loadMore" class="{{ $gridClasses }}">
        @foreach (range(1, 8) as $placeholder)
            <x-skeleton :tile="true" />
        @endforeach
    </div>

    @if ($printings->isNotEmpty())
        <div class="flex items-center gap-3">
            <span class="h-px flex-1 bg-raised"></span>
            <span class="text-small tabular-nums text-faint">{{ __('storefront.results.shown_of', ['shown' => number_format($printings->count()), 'total' => number_format($total)]) }}</span>
            <span class="h-px flex-1 bg-raised"></span>
        </div>

        @if ($next !== null)
            <button
                type="button"
                wire:click="loadMore"
                wire:loading.attr="disabled"
                wire:target="loadMore"
                class="flex min-h-12 w-full items-center justify-center rounded-control border border-line-strong bg-surface text-body font-medium tabular-nums text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none"
            >{{ $next['from'] === $next['to'] ? __('storefront.set.load_number', ['number' => $next['from']]) : __('storefront.set.load_numbers', ['from' => $next['from'], 'to' => $next['to']]) }}</button>
        @endif
    @endif
</div>
