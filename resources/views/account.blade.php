@php
    $receiptIcon = new \Illuminate\Support\HtmlString('<svg width="34" height="34" viewBox="0 0 20 20" fill="none"><path d="M5 2.8h10v14.4l-2.5-1.5-2.5 1.5-2.5-1.5L5 17.2V2.8Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M7.6 7h4.8M7.6 10h4.8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>');
    $timezone = config('shop.timezone');
    $placed = fn (\Carbon\CarbonInterface $at): string => $at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M Y');
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $heartIcon = new \Illuminate\Support\HtmlString('<svg width="34" height="34" viewBox="0 0 34 34" fill="none"><path d="M17 28S6 21.4 6 14a5.8 5.8 0 0 1 11-2.4A5.8 5.8 0 0 1 28 14c0 7.4-11 14-11 14Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>');
    $bellIcon = new \Illuminate\Support\HtmlString('<svg width="34" height="34" viewBox="0 0 34 34" fill="none"><path d="M17 5.5a8 8 0 0 0-8 8v5.2L6.5 23h21l-2.5-4.3v-5.2a8 8 0 0 0-8-8Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M13.5 26.5a3.5 3.5 0 0 0 7 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>');
    $linkButton = 'inline-flex min-h-11 items-center rounded-control border border-line px-3 text-small text-muted transition-[color,border-color] duration-160 ease-ui hover:border-line-strong hover:text-ink motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
@endphp

<x-layouts.storefront :title="__('storefront.account.title')">
    <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <div>
            <h1 class="font-display text-h2 font-medium lg:text-h1">{{ $user->name }}</h1>
            <p class="mt-1 text-small text-muted">
                @if ($user->email !== null)
                    {{ $user->email }}
                @else
                    {{ $user->hasLine() ? __('storefront.account.line_status', ['name' => $user->line_display_name ?? '']) : __('storefront.account.line_not_linked') }}
                @endif
            </p>
        </div>

        <x-auth.status />

        @if ($user->email !== null && ! $user->hasVerifiedEmail())
            <section aria-labelledby="account-verify-heading" class="flex flex-col items-start gap-3 rounded-card border border-line bg-sunken p-4">
                <h2 id="account-verify-heading" class="font-display text-card font-medium">{{ __('storefront.account.verify_title') }}</h2>
                <p class="text-small text-muted">{{ __('storefront.account.verify_text', ['email' => $user->email]) }}</p>
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <button type="submit" class="{{ $secondaryButton }}">{{ __('storefront.account.verify_resend') }}</button>
                </form>
            </section>
        @endif

        <section aria-labelledby="account-orders-heading">
            <h2 id="account-orders-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.account.orders') }}</h2>

            @if ($orders->isEmpty())
                <x-empty-state :title="__('storefront.account.orders_empty')" :hint="__('storefront.account.orders_empty_hint')" :icon="$receiptIcon">
                    <a href="{{ route('search') }}" class="{{ $primaryButton }}">{{ __('storefront.cart.search') }}</a>
                </x-empty-state>
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($orders as $order)
                        <li>
                            <a
                                href="{{ route('order.show', $order->number) }}"
                                aria-label="{{ __('storefront.account.order_open', ['number' => $order->number]) }}"
                                class="flex min-h-14 items-center justify-between gap-3 rounded-card border border-line bg-sunken p-3 transition-[border-color] duration-160 ease-ui hover:border-line-strong motion-reduce:transition-none"
                            >
                                <span class="min-w-0">
                                    <span class="block text-small font-medium tabular-nums">{{ $order->number }}</span>
                                    <span class="block text-label text-faint tabular-nums">{{ $placed($order->placed_at) }}</span>
                                </span>
                                <span class="flex flex-none flex-col items-end gap-1">
                                    <x-order-status-badge :status="$order->status" />
                                    <x-price :satang="$order->total_satang" class="text-small font-medium" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($orders->hasPages())
                    <div class="mt-4">{{ $orders->links() }}</div>
                @endif
            @endif
        </section>

        <section aria-labelledby="account-wishlist-heading">
            <h2 id="account-wishlist-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.account.wishlist') }}</h2>

            @if ($wishlist->isEmpty())
                <x-empty-state :title="__('storefront.account.wishlist_empty')" :hint="__('storefront.account.wishlist_empty_hint')" :icon="$heartIcon">
                    <a href="{{ route('search') }}" class="{{ $secondaryButton }}">{{ __('storefront.cart.search') }}</a>
                </x-empty-state>
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($wishlist as $item)
                        @php
                            $printing = $item->printing;
                            $stock = $printing->stockSummary();
                        @endphp
                        <li class="flex items-center gap-3 rounded-card border border-line bg-sunken p-3">
                            <a href="{{ route('card.show', ['card' => $printing->card, 'p' => $printing->id]) }}" class="flex min-h-14 min-w-0 flex-1 items-center gap-3 rounded-control">
                                <x-card-art :printing="$printing" size="w-10" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-small font-medium">{{ $printing->display_name }}</span>
                                    <span class="block truncate text-label text-faint tabular-nums">{{ $printing->display_code }}</span>
                                </span>
                                <span class="flex-none text-right text-small font-medium">
                                    @if ($stock['from_satang'] !== null)
                                        {{ __('storefront.account.wishlist_from') }} <x-price :satang="$stock['from_satang']" />
                                    @else
                                        <span class="text-faint">{{ __('storefront.account.wishlist_sold_out') }}</span>
                                    @endif
                                </span>
                            </a>
                            <form method="POST" action="{{ route('account.wishlist.destroy', $item) }}" class="flex-none">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="{{ __('storefront.account.wishlist_remove_label', ['card' => $printing->display_name]) }}" class="{{ $linkButton }}">{{ __('storefront.account.wishlist_remove') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="account-alerts-heading">
            <h2 id="account-alerts-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.account.alerts') }}</h2>

            @if ($priceAlerts->isEmpty() && $restockAlerts->isEmpty())
                <x-empty-state :title="__('storefront.account.alerts_empty')" :hint="__('storefront.account.alerts_empty_hint')" :icon="$bellIcon" />
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($priceAlerts as $alert)
                        @php
                            $variant = $alert->variant;
                            $printing = $variant->printing;
                            $cardUrl = route('card.show', ['card' => $printing->card, 'p' => $printing->id]);
                            $status = $alert->is_active ? __('storefront.account.alert_price_waiting') : __('storefront.account.alert_fired', ['date' => $placed($alert->last_fired_at ?? $alert->updated_at)]);
                        @endphp
                        <li class="flex items-center gap-3 rounded-card border border-line bg-sunken p-3">
                            <a href="{{ $cardUrl }}" class="flex min-h-14 min-w-0 flex-1 flex-col justify-center gap-1 rounded-control">
                                <span class="truncate text-small font-medium">{{ $printing->display_name }}</span>
                                <span class="line-clamp-2 text-label text-faint">{{ $printing->display_code }} · {{ $printing->language->shortCode() }} · {{ $variant->conditionFinishLabel() }}</span>
                                <span class="flex flex-wrap items-baseline gap-x-3 text-label tabular-nums text-muted">
                                    <span>{{ __('storefront.account.alert_price_target', ['target' => \App\Support\Money::fromSatang($alert->target_satang)->format()]) }}</span>
                                    @if ($variant->price_satang !== null)
                                        <span @class(['text-stock font-medium' => ! $alert->is_active])>{{ __('storefront.account.alert_price_now', ['price' => \App\Support\Money::fromSatang($variant->price_satang)->format()]) }}</span>
                                    @endif
                                </span>
                                <span @class(['text-label lg:hidden', 'text-muted' => $alert->is_active, 'text-stock' => ! $alert->is_active])>{{ $status }}</span>
                            </a>
                            <span @class(['hidden flex-none text-label lg:block', 'text-muted' => $alert->is_active, 'text-stock' => ! $alert->is_active])>{{ $status }}</span>
                            @unless ($alert->is_active)
                                <a href="{{ $cardUrl }}" class="{{ $linkButton }} flex-none">{{ __('storefront.account.alert_view_card') }}</a>
                            @endunless
                            <form method="POST" action="{{ route('account.alerts.price.destroy', $alert) }}" class="flex-none">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="{{ __('storefront.account.alert_remove_label', ['card' => $printing->display_name]) }}" class="{{ $linkButton }}">{{ $alert->is_active ? __('storefront.account.alert_remove') : __('storefront.account.alert_delete') }}</button>
                            </form>
                        </li>
                    @endforeach
                    @foreach ($restockAlerts as $alert)
                        @php
                            $variant = $alert->variant;
                            $printing = $variant->printing;
                            $status = $alert->is_active ? __('storefront.account.alert_restock_waiting') : __('storefront.account.alert_fired', ['date' => $placed($alert->notified_at ?? $alert->updated_at)]);
                        @endphp
                        <li class="flex items-center gap-3 rounded-card border border-line bg-sunken p-3">
                            <a href="{{ route('card.show', ['card' => $printing->card, 'p' => $printing->id]) }}" class="flex min-h-14 min-w-0 flex-1 flex-col justify-center gap-1 rounded-control">
                                <span class="truncate text-small font-medium">{{ $printing->display_name }}</span>
                                <span class="line-clamp-2 text-label text-faint">{{ $printing->display_code }} · {{ $printing->language->shortCode() }} · {{ $variant->conditionFinishLabel() }}</span>
                                <span @class(['text-label lg:hidden', 'text-muted' => $alert->is_active, 'text-stock' => ! $alert->is_active])>{{ $status }}</span>
                            </a>
                            <span @class(['hidden flex-none text-label lg:block', 'text-muted' => $alert->is_active, 'text-stock' => ! $alert->is_active])>{{ $status }}</span>
                            <form method="POST" action="{{ route('account.alerts.restock.destroy', $alert) }}" class="flex-none">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="{{ __('storefront.account.alert_remove_label', ['card' => $printing->display_name]) }}" class="{{ $linkButton }}">{{ $alert->is_active ? __('storefront.account.alert_remove') : __('storefront.account.alert_delete') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($user->hasLine() || ($lineConfigured && ! $user->is_staff))
            <section aria-labelledby="account-line-heading" class="flex flex-col items-start gap-3 rounded-card border border-line bg-sunken p-4">
                <h2 id="account-line-heading" class="font-display text-card font-medium">{{ __('storefront.account.line_section') }}</h2>

                @if ($user->hasLine())
                    <p class="text-small text-muted">{{ __('storefront.account.line_linked_as', ['name' => $user->line_display_name ?? '']) }}</p>
                    <form method="POST" action="{{ route('line.unlink') }}">
                        @csrf
                        <button type="submit" class="{{ $secondaryButton }}">{{ __('storefront.account.line_unlink') }}</button>
                    </form>
                @elseif ($lineConfigured && ! $user->is_staff)
                    <x-auth.line-button :full="false" variant="secondary">{{ __('storefront.account.line_link') }}</x-auth.line-button>
                @endif
            </section>
        @endif

        <section aria-labelledby="account-notifications-heading" class="flex flex-col items-start gap-3 rounded-card border border-line bg-sunken p-4">
            <h2 id="account-notifications-heading" class="font-display text-card font-medium">{{ __('storefront.account.notifications') }}</h2>

            @if ($user->hasLine() && $lineMessagingConfigured)
                <form method="POST" action="{{ route('account.notifications') }}" class="flex flex-col items-start gap-3">
                    @csrf
                    <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control text-small text-ink has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                        <x-checkbox name="line_notify_opt_in" value="1" :checked="$user->line_notify_opt_in" aria-describedby="account-notify-hint" />
                        {{ __('storefront.account.notify_line') }}
                    </label>
                    <p id="account-notify-hint" class="text-small text-muted">
                        {{ __('storefront.account.notify_line_hint') }}
                        @if (filled(config('shop.line_oa_url')))
                            <a href="{{ config('shop.line_oa_url') }}" target="_blank" rel="noopener noreferrer" class="rounded-tag text-ink underline underline-offset-2 hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ __('storefront.account.notify_line_add_friend') }}</a>
                        @endif
                    </p>
                    <button type="submit" class="{{ $secondaryButton }}">{{ __('storefront.account.notify_save') }}</button>
                </form>
            @elseif ($user->email !== null)
                <p class="text-small text-muted">{{ __('storefront.account.notify_email') }}</p>
            @elseif ($user->hasLine())
                <p class="text-small text-muted">{{ __('storefront.account.line_not_open') }}</p>
            @else
                <p class="text-small text-muted">{{ __('storefront.account.no_channel') }}</p>
            @endif
        </section>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="{{ $secondaryButton }}">{{ __('storefront.account.logout') }}</button>
        </form>
    </div>
</x-layouts.storefront>
