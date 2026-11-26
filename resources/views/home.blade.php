<x-layouts.storefront>
    @if ($showDemoNotice)
        <x-demo-notice />
    @endif

    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 pb-8 pt-5 lg:gap-12 lg:px-8 lg:pb-16 lg:pt-10">
        @if ($heroSet !== null)
            <section class="flex items-center gap-4 rounded-sheet border border-accent/30 bg-sunken p-4 lg:gap-7 lg:p-8">
                <x-card-art size="w-24 lg:w-36" :foil="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-label font-medium text-accent">{{ __('storefront.home.latest_set', ['game' => $heroSet->game_name]) }}</p>
                    <h1 class="mt-2 font-display text-h3 font-medium lg:text-h1">{{ $heroSet->name_en }}</h1>
                    <p class="mt-2 text-label text-faint lg:text-small">
                        {{ $heroSet->display_code }} · <x-set-counts :set="$heroSet" /> · {{ __('storefront.home.released', ['date' => $heroSet->released_on ? $heroSet->released_on->locale('th')->translatedFormat('j M').' '.($heroSet->released_on->year + 543) : '—']) }}
                    </p>
                    <a href="{{ route('set.show', $heroSet) }}" class="mt-4 inline-flex min-h-12 items-center rounded-control bg-accent px-6 text-body font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none">{{ __('storefront.home.browse_set') }}</a>
                </div>
            </section>
        @endif

        {{-- The desktop header links to the buylist; the phone's bottom nav is full, so the link lives here. --}}
        <a href="{{ route('buylist') }}" class="flex min-h-14 items-center gap-3 rounded-card border border-line bg-surface px-4 py-3 text-ink transition-[background-color] duration-160 ease-ui hover:bg-raised motion-reduce:transition-none lg:hidden">
            <span class="min-w-0 flex-1 text-body font-medium">{{ __('storefront.home.sell_cards') }}</span>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" class="flex-none text-faint"><path d="m6 3 5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>

        @if ($newSingles->isNotEmpty())
            <section>
                <div class="mb-3 flex items-baseline justify-between gap-4">
                    <h2 class="font-display text-card font-medium lg:text-h3">{{ __('storefront.home.new_singles') }}</h2>
                    <a href="{{ route('search') }}" class="inline-flex min-h-11 items-center text-small text-accent hover:text-ink">{{ __('storefront.home.see_all') }}</a>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($newSingles as $printing)
                        <x-card-tile :printing="$printing" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($latestSets->isNotEmpty())
            <section>
                <h2 class="mb-3 font-display text-card font-medium lg:text-h3">{{ __('storefront.home.latest_sets') }}</h2>
                <div class="flex flex-col divide-y divide-line overflow-hidden rounded-card border border-line">
                    @foreach ($latestSets as $set)
                        <a href="{{ route('set.show', $set) }}" class="flex min-h-14 items-center gap-3 bg-surface px-4 py-3 text-ink transition-[background-color] duration-160 ease-ui hover:bg-raised motion-reduce:transition-none">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-body font-medium">{{ $set->display_code }} — {{ $set->name_en }}</span>
                                <x-set-counts :set="$set" class="mt-1 block text-label tabular-nums text-faint" />
                            </span>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" class="flex-none text-faint"><path d="m6 3 5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($sealedProducts->isNotEmpty())
            <section>
                <h2 class="mb-3 font-display text-card font-medium lg:text-h3">{{ __('storefront.sealed.section') }}</h2>
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    @foreach ($sealedProducts as $product)
                        <x-sealed-tile :product="$product" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($heroSet === null && $newSingles->isEmpty() && $sealedProducts->isEmpty())
            <x-empty-state :title="__('storefront.home.empty')" />
        @else
            <x-trust-row class="justify-center" />
        @endif
    </div>
</x-layouts.storefront>
