@props(['title' => null, 'results' => false, 'bottomNav' => true])

<!DOCTYPE html>
<html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600&amp;family=Anuphan:wght@500;600&amp;display=swap">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-bg pb-24 font-sans text-body text-ink antialiased lg:pb-0">
        <header class="relative z-40 border-b border-line">
            <div @class(['mx-auto flex max-w-7xl flex-wrap items-center gap-x-5 gap-y-3 px-4 pb-3 pt-4 lg:min-h-20 lg:px-8 lg:py-4', 'max-lg:flex-nowrap max-lg:gap-x-2' => $results])>
                @if ($results)
                    <a
                        href="{{ route('home') }}"
                        x-data
                        x-on:click="if (history.length > 1) { $event.preventDefault(); history.back(); }"
                        aria-label="{{ __('storefront.search.back') }}"
                        class="-ml-2 flex size-11 flex-none items-center justify-center rounded-control text-ink transition-[color,background-color] duration-160 ease-ui hover:bg-surface motion-reduce:transition-none lg:hidden"
                    >
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12.5 4 6.5 10l6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                @endif
                <a href="{{ route('home') }}" @class(['-mx-2 flex min-h-11 flex-none items-center gap-2 rounded-control px-2 text-ink', 'max-lg:hidden' => $results])>
                    <span aria-hidden="true" class="text-card leading-none text-accent lg:text-h3">&#9670;</span>
                    <span class="font-display text-card font-medium lg:text-h3">{{ config('app.name') }}</span>
                </a>

                <livewire:storefront.search-box />

                <a
                    href="{{ route('deck.import') }}"
                    @if (request()->routeIs('deck.import')) aria-current="page" @endif
                    class="inline-flex min-h-11 flex-none items-center gap-2 rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none max-lg:hidden"
                >
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="4" y="2.8" width="12" height="14.4" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M7.2 7h5.6M7.2 10h5.6M7.2 13h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    {{ __('storefront.nav.deck') }}
                </a>

                <a
                    href="{{ route('buylist') }}"
                    @if (request()->routeIs('buylist*')) aria-current="page" @endif
                    class="inline-flex min-h-11 flex-none items-center gap-2 rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none max-lg:hidden"
                >
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 6.5h14v9.7H3V6.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M6.5 6.5V4.2h7v2.3M10 9.2v4.2M8.2 11.3h3.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    {{ __('storefront.nav.buylist') }}
                </a>

                <livewire:storefront.cart-link variant="header" />

                <a
                    href="{{ route(auth()->check() ? 'account' : 'login') }}"
                    @if (request()->routeIs('account', 'login')) aria-current="page" @endif
                    class="inline-flex min-h-11 flex-none items-center gap-2 rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none max-lg:hidden"
                >
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.8 17c.5-3 3-4.8 6.2-4.8s5.7 1.8 6.2 4.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    {{ auth()->check() ? __('storefront.nav.account') : __('storefront.nav.login') }}
                </a>
            </div>

            @if ($games->isNotEmpty())
                <nav aria-label="{{ __('storefront.nav.games') }}" @class(['lg:border-t lg:border-line', 'max-lg:hidden' => $results])>
                    <ul class="mx-auto flex max-w-7xl gap-2 overflow-x-auto px-4 pb-3 lg:px-8 lg:py-4">
                        @php $currentGame = request()->route('game'); @endphp
                        @foreach ($games as $game)
                            @php $isCurrent = $currentGame instanceof \App\Models\Game && $currentGame->is($game); @endphp
                            <li class="flex-none">
                                <a
                                    href="{{ route('game.show', $game) }}"
                                    @if ($isCurrent) aria-current="page" @endif
                                    @class([
                                        'inline-flex min-h-11 items-center rounded-control border px-4 text-small transition-[color,border-color] duration-160 ease-ui motion-reduce:transition-none lg:rounded-full',
                                        'border-line-strong bg-raised text-ink' => $isCurrent,
                                        'border-line bg-sunken text-muted hover:text-ink' => ! $isCurrent,
                                    ])
                                >{{ $game->name_en }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </header>

        <main>
            {{ $slot }}
        </main>

        @if ($bottomNav)
        <nav aria-label="{{ __('storefront.nav.bottom') }}" class="fixed inset-x-0 bottom-0 z-30 flex border-t border-line bg-sunken px-1 pb-4 pt-2 lg:hidden">
            <a
                href="{{ route('home') }}"
                @if (request()->routeIs('home')) aria-current="page" @endif
                @class([
                    'flex min-h-12 flex-1 flex-col items-center justify-center gap-1 rounded-control text-label',
                    'font-medium text-accent' => request()->routeIs('home'),
                    'text-faint' => ! request()->routeIs('home'),
                ])
            >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 8.6 10 3.2l7 5.4V17H3V8.6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                {{ __('storefront.nav.home') }}
            </a>
            <a
                href="{{ route('deck.import') }}"
                @if (request()->routeIs('deck.import')) aria-current="page" @endif
                @class([
                    'flex min-h-12 flex-1 flex-col items-center justify-center gap-1 rounded-control text-label',
                    'font-medium text-accent' => request()->routeIs('deck.import'),
                    'text-faint' => ! request()->routeIs('deck.import'),
                ])
            >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="4" y="2.8" width="12" height="14.4" rx="1.6" stroke="currentColor" stroke-width="1.5"/><path d="M7.2 7h5.6M7.2 10h5.6M7.2 13h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                {{ __('storefront.nav.deck') }}
            </a>
            <div class="flex flex-1 justify-center">
                <button
                    type="button"
                    x-data
                    x-on:click.stop="$dispatch('open-search')"
                    aria-label="{{ __('storefront.search.open') }}"
                    class="-mt-5 flex size-14 items-center justify-center rounded-full bg-accent text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none"
                >
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.4" stroke="currentColor" stroke-width="2"/><path d="M15.4 15.4 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
            </div>
            <livewire:storefront.cart-link variant="bottom" />
            <a
                href="{{ route(auth()->check() ? 'account' : 'login') }}"
                @if (request()->routeIs('account')) aria-current="page" @endif
                @class([
                    'flex min-h-12 flex-1 flex-col items-center justify-center gap-1 rounded-control text-label',
                    'font-medium text-accent' => request()->routeIs('account'),
                    'text-faint' => ! request()->routeIs('account'),
                ])
            >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.5"/><path d="M3.8 17c.5-3 3-4.8 6.2-4.8s5.7 1.8 6.2 4.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                {{ __('storefront.nav.account') }}
            </a>
        </nav>
        @endif

        @livewireScripts
    </body>
</html>
