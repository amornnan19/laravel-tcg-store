@php
    $heading = match (true) {
        $pageGame !== null && $text !== '' => $pageGame->name_en.' · '.__('storefront.results.heading_query', ['query' => $text]),
        $pageGame !== null => $pageGame->name_en,
        $text !== '' => __('storefront.results.heading_query', ['query' => $text]),
        default => __('storefront.results.title_all'),
    };
    $gridClasses = 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5';
    $loadingTargets = 'toggle,toggleFlag,setPrice,clearGroup,clearAll,restore,sort';
@endphp

<div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 pb-24 pt-4 lg:flex-row lg:items-start lg:gap-8 lg:px-8 lg:pb-16 lg:pt-8">
    <a href="#results-heading" class="sr-only rounded-control bg-accent px-4 py-3 text-small font-medium text-accent-ink focus-visible:not-sr-only focus-visible:fixed focus-visible:left-4 focus-visible:top-4 focus-visible:z-50">{{ __('storefront.results.skip') }}</a>

    <livewire:storefront.filter-panel :panel="$panel" />

    <section aria-labelledby="results-heading" class="min-w-0 flex-1">
        <p aria-live="polite" class="sr-only">{{ __('storefront.results.announce', ['n' => $page->total]) }}</p>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:gap-x-4">

            <h1 id="results-heading" tabindex="-1" class="min-w-0 font-display text-h3 font-medium focus:outline-none lg:text-h2">
                {{ $heading }}
                <span class="text-small font-normal tabular-nums text-faint">· {{ __('storefront.results.count', ['n' => number_format($page->total)]) }}</span>
            </h1>

            <div class="flex items-center justify-end gap-3 max-lg:order-last lg:col-start-2 lg:row-start-1">
                <label class="flex flex-none items-center gap-2 text-small text-muted">
                    <span class="sr-only lg:not-sr-only">{{ __('storefront.results.sort_label') }}</span>
                    <span class="relative">
                        <select
                            wire:change="$set('sort', $event.target.value)"
                            class="min-h-11 appearance-none rounded-control border border-line bg-surface py-0 pl-3 pr-9 text-small text-muted transition-[color,border-color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none"
                        >
                            @foreach ($sorts as $option)
                                <option value="{{ $option }}" @selected($activeSort === $option)>{{ __('storefront.results.sort.'.$option) }}</option>
                            @endforeach
                        </select>
                        <svg width="11" height="11" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-faint"><path d="m2.5 4.5 3.5 3.5 3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                </label>

                <div role="group" aria-label="{{ __('storefront.results.list_label') }}" class="flex flex-none overflow-hidden rounded-control border border-line">
                    @foreach (['grid' => 'view_grid', 'list' => 'view_list'] as $mode => $label)
                        <button
                            type="button"
                            wire:click="$set('view', '{{ $mode }}')"
                            aria-pressed="{{ $view === $mode ? 'true' : 'false' }}"
                            aria-label="{{ __('storefront.results.'.$label) }}"
                            @class([
                                'flex size-11 items-center justify-center transition-[color,background-color] duration-160 ease-ui motion-reduce:transition-none',
                                'bg-raised text-accent' => $view === $mode,
                                'text-faint hover:text-ink' => $view !== $mode,
                            ])
                        >
                            @if ($mode === 'grid')
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="2" y="2" width="5" height="5"/><rect x="9" y="2" width="5" height="5"/><rect x="2" y="9" width="5" height="5"/><rect x="9" y="9" width="5" height="5"/></svg>
                            @else
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($page->limited)
                <p role="status" class="rounded-control border border-line bg-sunken px-4 py-3 text-small text-low lg:col-span-2">{{ __('storefront.results.limited') }}</p>
            @endif

            @if ($chips !== [])
                <ul aria-label="{{ __('storefront.results.applied') }}" class="-mx-4 flex gap-2 overflow-x-auto px-4 lg:mx-0 lg:col-span-2 lg:flex-wrap lg:overflow-visible lg:px-0">
                    @foreach ($chips as $chip)
                        @php
                            $remove = match (true) {
                                $chip['value'] !== null => "toggle('{$chip['group']}', '".addslashes($chip['value'])."')",
                                in_array($chip['group'], ['stock', 'foil', 'photo'], true) => "toggleFlag('{$chip['group']}')",
                                default => "clearGroup('{$chip['group']}')",
                            };
                        @endphp
                        <li class="flex-none" wire:key="chip-{{ $chip['group'] }}-{{ $chip['value'] ?? 'on' }}">
                            <x-filter-chip
                                :active="true"
                                :remove="true"
                                :aria-label="__('storefront.results.remove_filter', ['label' => $chip['label']])"
                                wire:click="{{ $remove }}"
                            >{{ $chip['label'] }}</x-filter-chip>
                        </li>
                    @endforeach
                    @if (count($chips) > 1)
                        <li class="flex-none">
                            <button type="button" wire:click="clearAll" class="inline-flex min-h-11 items-center px-3 text-small text-accent transition-[color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none">{{ __('storefront.results.clear_all') }}</button>
                        </li>
                    @endif
                </ul>
            @endif
        </div>
        <div class="mt-4" data-view="{{ $view }}" wire:loading.class="opacity-60" wire:target="{{ $loadingTargets }}">
            @if ($page->printings->isEmpty())
                <x-empty-state
                    :title="$text !== '' ? __('storefront.results.empty.title_query', ['query' => $text]) : __('storefront.results.empty.title')"
                    :hint="$suggestion !== null
                        ? __('storefront.results.empty.suggestion', ['n' => number_format($suggestion['count']), 'filter' => $suggestion['label']])
                        : ($chips === [] ? __('storefront.results.empty.hint') : null)"
                >
                    @if ($suggestion !== null)
                        <button
                            type="button"
                            wire:click="clearGroup('{{ $suggestion['group'] }}')"
                            class="inline-flex min-h-11 items-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none"
                        >{{ __('storefront.results.empty.remove', ['name' => $suggestion['name']]) }}</button>
                    @endif
                    @if (count($chips) > ($suggestion !== null ? collect($chips)->where('group', $suggestion['group'])->count() : 0))
                        <button type="button" wire:click="clearAll" class="inline-flex min-h-11 items-center px-3 text-small text-accent transition-[color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none">{{ __('storefront.results.empty.clear_all') }}</button>
                    @endif
                </x-empty-state>
            @elseif ($view === 'list')
                <div class="flex flex-col gap-2">
                    @foreach ($page->printings as $printing)
                        <x-card-row :printing="$printing" wire:key="row-{{ $printing->id }}" />
                    @endforeach
                </div>
            @else
                <div class="{{ $gridClasses }}">
                    @foreach ($page->printings as $printing)
                        <x-card-tile :printing="$printing" wire:key="tile-{{ $printing->id }}" />
                    @endforeach
                </div>
            @endif
        </div>

        <div wire:loading.delay wire:target="loadMore" class="mt-3 {{ $gridClasses }}">
            @foreach (range(1, 6) as $placeholder)
                <x-skeleton :tile="true" />
            @endforeach
        </div>

        @if ($page->printings->isNotEmpty())
            <div class="mt-6 flex items-center gap-3">
                <span class="h-px flex-1 bg-raised"></span>
                <span class="text-small tabular-nums text-faint">{{ __('storefront.results.shown_of', ['shown' => number_format($shown), 'total' => number_format($page->total)]) }}</span>
                <span class="h-px flex-1 bg-raised"></span>
            </div>

            @if ($shown < $page->total)
                <button
                    type="button"
                    wire:click="loadMore"
                    wire:loading.attr="disabled"
                    wire:target="loadMore"
                    class="mt-4 flex min-h-12 w-full items-center justify-center rounded-control border border-line-strong bg-surface text-body font-medium text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none"
                >{{ __('storefront.results.load_more', ['n' => \App\Livewire\Storefront\ResultGrid::PER_PAGE]) }}</button>
            @endif
        @endif
    </section>

    <button
        type="button"
        x-data
        x-on:click="$dispatch('open-filters')"
        class="fixed inset-x-4 bottom-24 z-20 flex min-h-12 items-center justify-center gap-2 rounded-full bg-accent text-body font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none lg:hidden"
    >
        <svg width="17" height="17" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 5h12M5.5 9h7M8 13h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        {{ __('storefront.results.filters') }}
        @if ($panel['active'] > 0)
            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-accent-ink px-1 text-small tabular-nums text-accent">{{ $panel['active'] }}</span>
        @endif
    </button>
</div>
