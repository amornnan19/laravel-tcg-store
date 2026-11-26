@php
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
    $timezone = config('shop.timezone');
    $when = fn (\Carbon\CarbonInterface $at): string => $at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M').' '.$at->copy()->setTimezone($timezone)->format('H:i');
    $address = $order->address;
@endphp

<x-layouts.storefront :title="__('storefront.order.title', ['number' => $order->number])" :results="true" :bottom-nav="false">
    <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-label text-faint">{{ __('storefront.order.number') }}</p>
                <h1 class="font-display text-h2 font-medium tabular-nums lg:text-h1">{{ $order->number }}</h1>
            </div>
            <x-order-status-badge :status="$order->status" />
        </div>

        @if (session('order_placed') === $order->id)
            <p role="status" class="rounded-control border border-stock/40 bg-stock/10 px-4 py-3 text-small font-medium text-stock">{{ __('storefront.order.placed_flash') }}</p>
        @endif

        @if ($order->isAwaitingPayment())
            <section aria-label="{{ __('storefront.payment.section') }}" class="rounded-card border border-line bg-sunken p-4">
                <livewire:storefront.payment-panel :order-id="$order->id" />
                <p class="mt-3 text-label text-muted">{{ __('storefront.order.emailed', ['email' => $order->contact_email]) }}</p>
            </section>
        @elseif ($order->status === \App\Enums\OrderStatus::Paid)
            <livewire:storefront.payment-panel :order-id="$order->id" />
        @elseif ($order->isCancelled())
            <section aria-labelledby="order-cancelled-heading" class="flex items-start gap-3 rounded-card border border-line bg-sunken p-4">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-danger"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6"/><path d="M7 7l6 6M13 7l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <div class="min-w-0 flex-1">
                    <h2 id="order-cancelled-heading" class="text-small font-medium">{{ __('storefront.order.cancelled') }}</h2>
                    @if (filled($order->cancel_reason))
                        <p class="mt-1 text-small text-muted">{{ $order->cancel_reason }}</p>
                    @endif
                    <p class="mt-1 text-small text-muted">{{ __('storefront.order.stock_returned') }}</p>
                    <a href="{{ route('search') }}" class="mt-3 inline-flex min-h-11 items-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none">{{ __('storefront.cart.search') }}</a>
                </div>
            </section>
        @endif

        <section aria-labelledby="order-timeline-heading">
            <h2 id="order-timeline-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.order.timeline') }}</h2>
            <ol class="flex flex-col gap-3 rounded-card border border-line bg-sunken p-4">
                @foreach ($order->timeline() as $step)
                    <li @class(['flex items-baseline justify-between gap-4 text-small', 'text-ink' => $step['at'] !== null, 'text-faint' => $step['at'] === null])>
                        <span @class(['font-medium' => $step['at'] !== null])>{{ __('storefront.order.'.$step['key']) }}</span>
                        <span class="tabular-nums">{{ $step['at'] === null ? '—' : $when($step['at']) }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <section aria-labelledby="order-items-heading">
            <h2 id="order-items-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.order.items') }}</h2>
            <ul class="flex flex-col gap-2">
                @foreach ($order->items as $item)
                    <li class="flex items-start gap-3 rounded-card border border-line bg-sunken p-3">
                        @php
                            $photo = $item->kind === 'graded' && $item->purchasable instanceof \App\Models\GradedItem ? $item->purchasable->photoUrl('front_path') : null;
                            $boxPhoto = $item->kind === 'sealed' && $item->purchasable instanceof \App\Models\SealedProduct ? $item->purchasable->imageUrl() : null;
                        @endphp
                        @if ($photo !== null)
                            <img src="{{ $photo }}" alt="" loading="lazy" class="aspect-[3/4] w-10 flex-none rounded-tag border border-line bg-surface object-cover">
                        @elseif ($item->kind === 'sealed')
                            <x-sealed-art :url="$boxPhoto" size="w-10" />
                        @else
                            <x-card-art size="w-10" :foil="$item->finish === 'foil'" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-small font-medium">{{ $item->card_name }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-faint">
                                @if ($item->kind === 'single')
                                    <span class="tabular-nums">{{ strtoupper(str_replace('-', '', $item->set_code)) }}-{{ $item->number }}</span>
                                    @if ($item->language !== null)
                                        <x-language-badge :language="$item->language" />
                                    @endif
                                    @if ($item->condition !== null)
                                        <x-condition-badge :condition="$item->condition" />
                                    @endif
                                    @if ($item->finish !== null)
                                        <span>{{ \App\Enums\Finish::from($item->finish)->label() }}</span>
                                    @endif
                                @else
                                    @if ($item->number !== null)
                                        <span class="tabular-nums">{{ strtoupper(str_replace('-', '', $item->set_code)) }}-{{ $item->number }}</span>
                                    @endif
                                    @if ($item->language !== null)
                                        <x-language-badge :language="$item->language" />
                                    @endif
                                    @if (filled($item->detail))
                                        <span>{{ $item->detail }}</span>
                                    @endif
                                @endif
                            </p>
                        </div>
                        <div class="flex-none text-right">
                            <x-price :satang="$item->line_total_satang" class="text-small font-medium" />
                            @if ($item->qty > 1)
                                <p class="mt-1 text-label text-faint tabular-nums"><x-price :satang="$item->unit_price_satang" /> &times; {{ $item->qty }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="order-address-heading">
            <h2 id="order-address-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.order.address') }}</h2>
            <div class="rounded-card border border-line bg-sunken p-4">
                <p class="text-small font-medium">{{ $address['recipient'] }} · <span class="font-normal tabular-nums text-muted">{{ $address['phone'] }}</span></p>
                <p class="mt-2 text-small text-muted">{{ $address['line1'] }} {{ $address['subdistrict'] }} {{ $address['district'] }}<br>{{ $address['province'] }} <span class="tabular-nums">{{ $address['postcode'] }}</span></p>
                <p class="mt-3 flex items-baseline justify-between gap-4 border-t border-line pt-3 text-small">
                    <span class="text-muted">{{ __('storefront.order.courier') }}</span>
                    <span>{{ $order->courier->label() }}</span>
                </p>
                @if ($order->latestPayment !== null)
                    <p class="mt-2 flex items-baseline justify-between gap-4 text-small">
                        <span class="text-muted">{{ __('storefront.order.payment_method') }}</span>
                        <span>{{ $order->latestPayment->method->label() }}</span>
                    </p>
                @endif
                @if ($order->shipped_at !== null && filled($order->tracking_number))
                    <p class="mt-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-small">
                        <span class="tabular-nums text-muted">{{ __('storefront.order.tracking', ['number' => $order->tracking_number]) }}</span>
                        <a href="{{ $order->courier->trackingUrl($order->tracking_number) }}" rel="noopener" target="_blank" class="inline-flex min-h-11 items-center text-accent hover:text-ink">{{ __('storefront.order.track') }}</a>
                    </p>
                @endif
            </div>
        </section>

        <section aria-labelledby="order-summary-heading">
            <h2 id="order-summary-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.order.summary') }}</h2>
            <div class="flex flex-col gap-1 rounded-card border border-line bg-sunken p-4 text-small text-muted">
                <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.items_total', ['n' => $order->items->count()]) }}</span><x-price :satang="$order->subtotal_satang" class="text-ink" /></p>
                <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.shipping', ['courier' => $order->courier->label()]) }}</span><x-price :satang="$order->shipping_satang" class="text-ink" /></p>
                @if ($order->protection_satang > 0)
                    <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.protection') }}</span><x-price :satang="$order->protection_satang" class="text-ink" /></p>
                @endif
                @if ($order->insurance_satang > 0)
                    <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.insurance', ['n' => $order->items->where('kind', 'graded')->sum('qty')]) }}<span class="block text-label text-faint">{{ __('storefront.checkout.insurance_note') }}</span></span><x-price :satang="$order->insurance_satang" class="text-ink" /></p>
                @endif
                <p class="mt-2 flex items-baseline justify-between gap-4 border-t border-line pt-3">
                    <span class="text-body font-medium text-ink">{{ __('storefront.checkout.total') }}</span>
                    <x-price :satang="$order->total_satang" class="text-h3 font-medium text-accent" />
                </p>
            </div>
        </section>
    </div>
</x-layouts.storefront>
