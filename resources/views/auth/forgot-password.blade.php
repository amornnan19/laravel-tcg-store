@php
    $primaryButton = 'inline-flex min-h-11 w-full items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
@endphp

<x-layouts.storefront :results="true" :title="__('storefront.auth.forgot_title')" :bottom-nav="false">
    <div class="mx-auto flex max-w-md flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.auth.forgot_title') }}</h1>

        <x-auth.status />

        <p class="text-small text-muted">{{ __('storefront.auth.forgot_intro') }}</p>

        <form method="POST" action="{{ route('password.email') }}" novalidate class="flex flex-col gap-4 rounded-card border border-line bg-sunken p-4">
            @csrf
            <x-auth.field name="email" type="email" :label="__('storefront.auth.fields.email')" autocomplete="email" :value="old('email')" />
            <button type="submit" class="{{ $primaryButton }}">{{ __('storefront.auth.forgot_submit') }}</button>
        </form>

        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center text-small text-accent hover:text-ink">{{ __('storefront.auth.back_to_login') }}</a>
    </div>
</x-layouts.storefront>
