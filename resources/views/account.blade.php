@php
    $receiptIcon = new \Illuminate\Support\HtmlString('<svg width="34" height="34" viewBox="0 0 20 20" fill="none"><path d="M5 2.8h10v14.4l-2.5-1.5-2.5 1.5-2.5-1.5L5 17.2V2.8Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M7.6 7h4.8M7.6 10h4.8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>');
    $timezone = config('shop.timezone');
    $placed = fn (\Carbon\CarbonInterface $at): string => $at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M Y');
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
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
