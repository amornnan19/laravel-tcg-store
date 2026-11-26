@php
    $lineConfigured = \App\Support\LineLogin::isConfigured();
    // One gold primary per screen: with the LINE button on it, the email submit is the secondary one.
    $submitButton = $lineConfigured
        ? 'inline-flex min-h-11 w-full items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none'
        : 'inline-flex min-h-11 w-full items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
@endphp

<x-layouts.storefront :results="true" :title="__('storefront.auth.register_title')" :bottom-nav="false">
    <div class="mx-auto flex max-w-md flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.auth.register_title') }}</h1>

        @if ($lineConfigured)
            <div class="flex flex-col gap-2">
                <x-auth.line-button>{{ __('storefront.auth.line_login') }}</x-auth.line-button>
                <p class="text-label text-muted">{{ __('storefront.auth.line_hint') }}</p>
            </div>

            <p class="flex items-center gap-3 text-label text-faint" aria-hidden="true">
                <span class="h-px flex-1 bg-line"></span>{{ __('storefront.auth.or') }}<span class="h-px flex-1 bg-line"></span>
            </p>
        @endif

        <form method="POST" action="{{ route('register') }}" novalidate class="flex flex-col gap-4 rounded-card border border-line bg-sunken p-4">
            @csrf
            <x-auth.field name="name" :label="__('storefront.auth.fields.name')" autocomplete="name" :value="old('name')" />
            <x-auth.field name="email" type="email" :label="__('storefront.auth.fields.email')" autocomplete="email" :value="old('email')" />
            <x-auth.field name="password" type="password" :label="__('storefront.auth.fields.password')" autocomplete="new-password" :hint="__('storefront.auth.hints.password')" />
            <x-auth.field name="password_confirmation" type="password" :label="__('storefront.auth.fields.password_confirmation')" autocomplete="new-password" />

            <button type="submit" class="{{ $submitButton }}">{{ __('storefront.auth.submit_register') }}</button>
        </form>

        <p class="text-small text-muted">
            {{ __('storefront.auth.have_account') }}
            <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center text-accent hover:text-ink">{{ __('storefront.auth.login_title') }}</a>
        </p>
    </div>
</x-layouts.storefront>
