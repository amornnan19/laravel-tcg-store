@php
    $set = $product->set;
    $game = $product->game;
@endphp

<x-layouts.storefront :title="$title" :results="true" :bottom-nav="false">
    <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <nav aria-label="{{ __('storefront.card.breadcrumb') }}" class="hidden text-small text-faint lg:block">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('home') }}" class="rounded-tag hover:text-ink">{{ __('storefront.nav.home') }}</a></li>
                <li aria-hidden="true">&rsaquo;</li>
                <li>{{ __('storefront.sealed.breadcrumb') }}</li>
                <li aria-hidden="true">&rsaquo;</li>
                <li aria-current="page" class="text-muted">{{ $product->displayName() }}</li>
            </ol>
        </nav>

        <div class="flex flex-col gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_26rem] lg:gap-x-12">
            <section class="min-w-0 lg:row-start-1">
                <x-sealed-art :product="$product" :label="true" class="mx-auto w-full max-w-md lg:mx-0 lg:max-w-lg" />
            </section>

            <div class="flex min-w-0 flex-col gap-5 lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:self-start">
                <div>
                    <h1 class="font-display text-h2 font-medium lg:text-h1">{{ $product->displayName() }}</h1>
                    <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-small text-muted">
                        <span class="inline-flex h-6 items-center rounded-full border border-line-strong bg-raised px-3 text-label text-muted">{{ $product->kind->label() }}</span>
                        @if ($product->is_preorder && $product->preorder !== null && $product->preorder->state() !== 'released')
                            <span class="inline-flex h-6 items-center rounded-full border border-accent/40 bg-accent/10 px-3 text-label font-medium text-accent">{{ __('storefront.preorder.badge', ['date' => $product->preorder->releaseAt()->locale('th')->translatedFormat('j M Y')]) }}</span>
                        @endif
                        @if ($game !== null)
                            <span>{{ $game->name_en }}</span>
                        @endif
                        @if ($set !== null)
                            <a href="{{ route('set.show', $set) }}" class="inline-flex min-h-11 items-center hover:text-ink">{{ $set->display_code }} — {{ $set->name_en }}</a>
                        @endif
                    </p>
                </div>

                @if ($product->is_preorder && $product->preorder !== null)
                    <livewire:storefront.preorder-box :preorder-id="$product->preorder->id" />
                @else
                    <livewire:storefront.sealed-buy-box :sealed-product-id="$product->id" />
                @endif

                @if (filled($product->description_th))
                    <p class="whitespace-pre-line text-small text-muted">{{ $product->description_th }}</p>
                @endif
            </div>
        </div>
    </div>
</x-layouts.storefront>
