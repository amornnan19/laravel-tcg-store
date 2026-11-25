@props(['title' => null])

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
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-5 gap-y-3 px-4 pb-3 pt-4 lg:min-h-20 lg:px-8 lg:py-4">
                <a href="{{ route('home') }}" class="-mx-2 flex min-h-11 flex-none items-center gap-2 rounded-control px-2 text-ink">
                    <span aria-hidden="true" class="text-card leading-none text-accent lg:text-h3">&#9670;</span>
                    <span class="font-display text-card font-medium lg:text-h3">{{ config('app.name') }}</span>
                </a>

                <livewire:storefront.search-box />
            </div>

            @if ($games->isNotEmpty())
                <nav aria-label="{{ __('storefront.nav.games') }}" class="lg:border-t lg:border-line">
                    <ul class="mx-auto flex max-w-7xl gap-2 overflow-x-auto px-4 pb-3 lg:px-8 lg:py-4">
                        @foreach ($games as $game)
                            <li class="inline-flex h-10 flex-none items-center rounded-control border border-line bg-sunken px-4 text-small text-muted lg:rounded-full">{{ $game->name_en }}</li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </header>

        <main>
            {{ $slot }}
        </main>

        <nav aria-label="{{ __('storefront.nav.bottom') }}" class="fixed inset-x-0 bottom-0 z-30 flex justify-center border-t border-line bg-sunken px-1 pb-4 pt-2 lg:hidden">
            <button
                type="button"
                x-data
                x-on:click.stop="$dispatch('open-search')"
                aria-label="{{ __('storefront.search.open') }}"
                class="-mt-5 flex size-14 items-center justify-center rounded-full bg-accent text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none"
            >
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.4" stroke="currentColor" stroke-width="2"/><path d="M15.4 15.4 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </nav>

        @livewireScripts
    </body>
</html>
