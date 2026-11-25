@props(['payload', 'amount', 'expiresAt' => null, 'account' => '', 'saveUrl' => null])

@php
    $money = \App\Support\Money::fromSatang($amount);
    $secondsLeft = $expiresAt === null ? 0 : max(0, (int) ceil(now()->diffInSeconds($expiresAt, false)));
    $secondary = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-raised px-3 text-label font-medium text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
@endphp

{{-- Keyed on the deadline: a morph keeps a matching element's Alpine state, so a new deadline needs a new element. --}}
<div
    wire:key="qr-{{ $expiresAt?->timestamp }}"
    @if ($expiresAt !== null)
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
    @endif
    {{ $attributes->class('grid grid-cols-[auto_1fr] items-start gap-x-4 gap-y-3 rounded-card border border-line-strong bg-surface p-4') }}
>
    {{-- Fixed white, never a theme token: a scanner needs a light ground in both themes. --}}
    <div role="img" aria-label="{{ __('storefront.payment.qr_label', ['amount' => $money->formatWithSatang()]) }}" class="size-28 flex-none rounded-control bg-white p-2 sm:row-span-2 sm:size-44 [&>svg]:size-full [&>svg]:[shape-rendering:crispEdges]">
        {!! \App\Support\QrImage::svg($payload) !!}
    </div>

    <div class="min-w-0">
        <p class="mb-1 text-label text-faint">{{ __('storefront.payment.promptpay') }}</p>
        <p class="text-h3 font-medium tabular-nums">{{ $money->formatWithSatang() }}</p>
        <p class="mt-1 text-small text-muted">{{ config('app.name') }}@if ($account !== '') · <span class="tabular-nums">{{ $account }}</span>@endif</p>

        @if ($expiresAt !== null)
            <p class="mt-2 flex items-center gap-2 text-label font-medium text-low">
                <span class="size-2 flex-none rounded-full bg-low" aria-hidden="true"></span>
                {{-- Not a live region: it ticks every second. --}}
                <span>{!! __('storefront.payment.qr_expires', ['time' => '<span class="tabular-nums" x-text="label">'.sprintf('%02d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60).'</span>']) !!}</span>
            </p>
        @endif
    </div>

    <div class="col-span-2 grid grid-cols-2 gap-2 sm:col-span-1 sm:col-start-2 sm:flex sm:flex-wrap">
        <button type="button" x-data="copyButton" data-copy="{{ $money->toDecimalString() }}" x-on:click="copy()" class="{{ $secondary }}">
            <span x-text="copied ? @js(__('storefront.payment.copied')) : @js(__('storefront.payment.copy_amount'))">{{ __('storefront.payment.copy_amount') }}</span>
        </button>
        @if ($saveUrl !== null)
            <a href="{{ $saveUrl }}" download x-data="saveQr" x-on:click="save($event)" class="{{ $secondary }}">{{ __('storefront.payment.save_qr') }}</a>
        @endif
    </div>
</div>
