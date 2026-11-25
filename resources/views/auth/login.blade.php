@php
    $lineConfigured = filled(config('services.line.client_id'));
    // One gold primary per screen: with the LINE button on it, the email submit is the secondary one.
    $submitButton = $lineConfigured
        ? 'inline-flex min-h-11 w-full items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none'
        : 'inline-flex min-h-11 w-full items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $link = 'inline-flex min-h-11 items-center text-small text-accent hover:text-ink';
@endphp

<x-layouts.storefront :results="true" :title="__('storefront.auth.login_title')" :bottom-nav="false">
    <div class="mx-auto flex max-w-md flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.auth.login_title') }}</h1>

        <x-auth.status />

        @if (session()->has('wishlist.pending'))
            <p class="text-small text-muted">{{ __('storefront.alerts.wishlist_login_note') }}</p>
        @endif

        @if ($lineConfigured)
            <div class="flex flex-col gap-2">
                <x-auth.line-button>{{ __('storefront.auth.line_login') }}</x-auth.line-button>
                <p class="text-label text-muted">{{ __('storefront.auth.line_hint') }}</p>
            </div>

            <p class="flex items-center gap-3 text-label text-faint" aria-hidden="true">
                <span class="h-px flex-1 bg-line"></span>{{ __('storefront.auth.or') }}<span class="h-px flex-1 bg-line"></span>
            </p>
        @endif

        <form method="POST" action="{{ route('login') }}" novalidate class="flex flex-col gap-4 rounded-card border border-line bg-sunken p-4">
            @csrf
            <x-auth.field name="email" type="email" :label="__('storefront.auth.fields.email')" autocomplete="email" :value="old('email')" />
            <x-auth.field name="password" type="password" :label="__('storefront.auth.fields.password')" autocomplete="current-password" />

            <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control text-small text-muted has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                <x-checkbox name="remember" value="1" :checked="(bool) old('remember')" />
                {{ __('storefront.auth.remember') }}
            </label>

            <button type="submit" class="{{ $submitButton }}">{{ __('storefront.auth.submit_login') }}</button>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-x-4">
            <a href="{{ route('password.request') }}" class="{{ $link }}">{{ __('storefront.auth.forgot_link') }}</a>
            <a href="{{ route('register') }}" class="{{ $link }}">{{ __('storefront.auth.register_link') }}</a>
        </div>
    </div>
</x-layouts.storefront>
