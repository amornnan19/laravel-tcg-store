{{-- First home-page visit of a session (HomeController decides). Same dialog pattern as the graded photo viewer: x-trap keeps focus inside and gives it back on close. No transitions: x-show does not animate the state it starts in, so it is reduced-motion safe by construction. --}}
<div
    x-data="{ open: true }"
    x-show="open"
    x-trap.noscroll="open"
    x-on:keydown.escape.window="open = false"
    x-on:click.self="open = false"
    class="fixed inset-0 z-50 flex items-end justify-center bg-bg/80 backdrop-blur-sm lg:items-center lg:p-6"
>
    <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="demo-notice-title"
        aria-describedby="demo-notice-lead"
        class="flex max-h-full w-full max-w-md flex-col gap-4 overflow-y-auto rounded-t-card border border-line bg-surface p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom))] lg:rounded-card lg:pb-6"
    >
        <div class="flex items-center justify-between gap-3">
            <span class="inline-flex items-center rounded-tag border border-accent px-2 py-1 text-label font-medium text-accent">{{ __('storefront.demo_notice.badge') }}</span>
            <img src="{{ asset('favicon.svg') }}" alt="" width="40" height="40" aria-hidden="true" class="size-10 flex-none">
        </div>

        <div class="flex flex-col gap-2">
            <h2 id="demo-notice-title" class="font-display text-h3 font-medium text-balance">{{ __('storefront.demo_notice.title') }}</h2>
            <p id="demo-notice-lead" class="text-pretty text-muted">{{ __('storefront.demo_notice.lead', ['name' => config('app.name')]) }}</p>
        </div>

        <ul class="flex flex-col gap-3 text-small">
            <li class="flex items-start gap-3">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-faint"><rect x="2.8" y="4.5" width="14.4" height="11" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M2.8 8.2h14.4M6 12.4h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <span class="text-pretty">{{ __('storefront.demo_notice.no_payment') }}</span>
            </li>
            <li class="flex items-start gap-3">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-faint"><circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M4 16.2c.6-2.8 3-4.2 6-4.2s5.4 1.4 6 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <span class="text-pretty">{{ __('storefront.demo_notice.no_personal_data') }}</span>
            </li>
            <li class="flex items-start gap-3">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-faint"><rect x="4.6" y="2.8" width="10.8" height="14.4" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M7.6 12.8l2-2.4 1.4 1.4 1.4-1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="text-pretty">{{ __('storefront.demo_notice.sample_data') }}</span>
            </li>
        </ul>

        <button type="button" autofocus x-on:click="open = false" class="inline-flex min-h-12 w-full items-center justify-center rounded-control bg-accent px-6 text-body font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none">{{ __('storefront.demo_notice.confirm') }}</button>

        @if (filled(config('shop.source_url')))
            <a href="{{ config('shop.source_url') }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center justify-center text-small text-muted underline hover:text-ink">{{ __('storefront.demo_notice.source') }}</a>
        @endif
    </div>
</div>
