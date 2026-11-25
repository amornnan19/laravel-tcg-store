@props(['expiresAt'])

@php
    $secondsLeft = max(0, (int) ceil(now()->diffInSeconds($expiresAt, false)));
@endphp

{{-- Keyed on the deadline: a morph keeps a matching element's Alpine state, so a new deadline needs a new element. --}}
<div
    wire:key="lock-{{ $expiresAt->timestamp }}"
    x-data="{
        ends: Date.parse(@js($expiresAt->toIso8601String())),
        skew: Date.parse(@js(now()->toIso8601String())) - Date.now(),
        left: {{ $secondsLeft }},
        ended: false,
        timer: null,
        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        tick() {
            this.left = Math.max(0, Math.ceil((this.ends - (Date.now() + this.skew)) / 1000));

            if (this.left === 0 && ! this.ended) {
                this.ended = true;
                clearInterval(this.timer);
                $wire.$refresh();
            }
        },
        get label() {
            return String(Math.floor(this.left / 60)).padStart(2, '0') + ':' + String(this.left % 60).padStart(2, '0');
        },
    }"
    {{ $attributes->class('flex items-center gap-3 rounded-control border border-low/40 bg-low/10 px-4 py-3 text-small font-medium text-low') }}
>
    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="flex-none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.6"/><path d="M10 5.8V10l2.8 1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    {{-- Not a live region: it ticks every second. The sentence below is the one thing announced, once, when time is up. --}}
    <p>{!! __('storefront.cart.lock', ['time' => '<span class="tabular-nums" x-text="label">'.sprintf('%02d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60).'</span>']) !!}</p>
    <p class="sr-only" aria-live="polite" x-text="ended ? @js(__('storefront.cart.lock_ended')) : ''"></p>
</div>
