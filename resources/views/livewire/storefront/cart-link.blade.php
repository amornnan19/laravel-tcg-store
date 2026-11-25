@php
    $count = $this->count;
    $label = $count > 0 ? __('storefront.nav.cart_count', ['n' => $count]) : null;
@endphp

@if ($variant === 'bottom')
    <a
        href="{{ route('cart') }}"
        @if ($current) aria-current="page" @endif
        @if ($label !== null) aria-label="{{ $label }}" @endif
        @class([
            'flex min-h-12 flex-1 flex-col items-center justify-center gap-1 rounded-control text-label',
            'font-medium text-accent' => $current,
            'text-faint' => ! $current,
        ])
    >
        <span class="relative">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3.5 5h13l-1.2 8.4H4.7L3.5 5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="7.4" cy="16.4" r="1.2" fill="currentColor"/><circle cx="12.8" cy="16.4" r="1.2" fill="currentColor"/></svg>
            @if ($count > 0)
                <span aria-hidden="true" class="absolute -right-3 -top-2 flex h-4 min-w-4 items-center justify-center rounded-tag bg-accent px-1 text-label font-medium tabular-nums text-accent-ink">{{ $count }}</span>
            @endif
        </span>
        {{ __('storefront.nav.cart') }}
    </a>
@else
    <a
        href="{{ route('cart') }}"
        @if ($current) aria-current="page" @endif
        @if ($label !== null) aria-label="{{ $label }}" @endif
        class="inline-flex min-h-11 flex-none items-center gap-2 rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none max-lg:hidden"
    >
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3.5 5h13l-1.2 8.4H4.7L3.5 5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="7.4" cy="16.4" r="1.2" fill="currentColor"/><circle cx="12.8" cy="16.4" r="1.2" fill="currentColor"/></svg>
        {{ __('storefront.nav.cart') }}
        @if ($count > 0)
            <span aria-hidden="true" class="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-accent px-1 text-label font-medium tabular-nums text-accent-ink">{{ $count }}</span>
        @endif
    </a>
@endif
