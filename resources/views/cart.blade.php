<x-layouts.storefront :title="__('storefront.cart.title')" :results="true">
    <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <div class="flex items-baseline justify-between gap-4">
            <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.cart.title') }}</h1>
            @if ($lines->isNotEmpty())
                <p class="text-small text-faint">{{ __('storefront.cart.lines', ['n' => $lines->count()]) }}</p>
            @endif
        </div>

        @if ($lines->isEmpty())
            <x-empty-state :title="__('storefront.cart.empty')">
                <a href="{{ route('search') }}" class="inline-flex min-h-11 items-center rounded-tag text-small text-accent hover:text-ink">{{ __('storefront.cart.search') }}</a>
            </x-empty-state>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($lines as $line)
                    @php $printing = $line->purchasable->printing; @endphp
                    <li class="flex items-center gap-3 rounded-card border border-line bg-sunken p-3">
                        <x-card-art :printing="$printing" size="w-12" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('card.show', ['card' => $printing->card, 'p' => $printing->id]) }}" class="block truncate text-card font-medium hover:text-accent">{{ $printing->display_name }}</a>
                            <p class="mt-1 text-label text-faint"><span class="tabular-nums">{{ $printing->display_code }}</span></p>
                        </div>
                        <span class="flex-none text-small tabular-nums text-muted">&times;{{ $line->qty }}</span>
                        <x-price :satang="$line->unit_price_satang * $line->qty" class="flex-none text-card font-medium" />
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.storefront>
