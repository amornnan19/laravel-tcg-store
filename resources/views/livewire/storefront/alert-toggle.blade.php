@php
    $on = $this->wishlisted;
@endphp

{{-- A single root, as Livewire needs. A toggle keeps one name; aria-pressed and the filled heart carry the state, and the
     visible label is part of the accessible name. --}}
<div class="flex-none">
    <button
        type="button"
        wire:click="toggle"
        aria-pressed="{{ $on ? 'true' : 'false' }}"
        aria-label="{{ __('storefront.alerts.wishlist_add') }}"
        @class([
            'inline-flex size-11 items-center justify-center gap-2 rounded-control border text-small transition-[color,border-color] duration-160 ease-ui hover:border-line-strong motion-reduce:transition-none lg:w-auto lg:px-3',
            'border-line-strong text-accent' => $on,
            'border-line text-muted hover:text-ink' => ! $on,
        ])
    >
        <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" fill="{{ $on ? 'currentColor' : 'none' }}"><path d="M10 16.5S3.5 12.6 3.5 8.3a3.4 3.4 0 0 1 6.5-1.4 3.4 3.4 0 0 1 6.5 1.4c0 4.3-6.5 8.2-6.5 8.2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        <span aria-hidden="true" class="hidden lg:inline">{{ __('storefront.alerts.wishlist_label_off') }}</span>
    </button>
</div>
