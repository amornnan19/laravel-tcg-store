@php
    $timezone = config('shop.timezone');
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $alertIcon = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-low"><circle cx="10" cy="10" r="7.6" stroke="currentColor" stroke-width="1.6"/><path d="M10 5.8V10l2.8 1.9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    $isShortfall = $payment?->shortfall_of_payment_id !== null;
    $qrExpired = $payment?->qr_payload !== null && ($payment->qr_expires_at === null || $payment->qr_expires_at->lte(now()));
    $bank = config('shop.bank');
    $promptPayId = (string) config('shop.promptpay_id');
    $accept = '.jpg,.jpeg,.png,.heic,.heif,image/jpeg,image/png,image/heic,image/heif';
    $received = $order->receivedSatang();
    $dueTime = $order->payment_due_at->copy()->setTimezone($timezone)->format('H:i');
    $dueDate = $order->paymentDueLabel();
    // A deposit is asked for before the whole total: name it, so the amount is not read as everything still owed.
    $isDeposit = $order->amountDueNowSatang() < $order->remainingSatang();
@endphp

<div class="flex flex-col gap-3">
    @if (in_array($state, ['pending', 'rejected', 'window_closed', 'submitted'], true))
        <div>
            @if ($state === 'submitted')
                {{-- A submitted order is not auto-cancelled while staff check the slip, so no deadline is shown. --}}
                <h2 id="order-payment-heading" class="text-small font-medium text-muted">{{ __('storefront.payment.submitted_heading') }}</h2>
                <p class="mt-3 flex items-baseline justify-between gap-4 text-small text-muted">
                    <span>{{ __('storefront.payment.transferred') }}</span>
                    <x-price :satang="$payment->amount_satang" />
                </p>
            @else
                @if ($state !== 'window_closed')
                    <h2 id="order-payment-heading" class="text-small font-medium text-low">@if ($order->status === \App\Enums\OrderStatus::AwaitingBalance){{ __('storefront.order.pay_balance_by') }} <span class="whitespace-nowrap">{{ $dueDate }}</span>@else{{ __('storefront.order.pay_by', ['time' => $dueTime]) }}@endif</h2>
                @endif
                <p @class(['flex items-baseline justify-between gap-4', 'mt-3' => $state !== 'window_closed'])>
                    <span class="text-small text-muted">{{ $isDeposit ? __('storefront.order.deposit_due') : __('storefront.order.amount_due') }}</span>
                    {{-- An overdue amount is not a call to pay in gold: the window is closed. --}}
                    <x-price :satang="$order->amountDueNowSatang()" :class="'text-h3 font-medium '.($state === 'window_closed' ? 'text-ink' : 'text-accent')" />
                </p>
            @endif
            @if ($received > 0)
                <p class="mt-1 flex items-baseline justify-between gap-4 text-label text-muted">
                    <span>{{ __('storefront.order.received') }}</span>
                    <x-price :satang="$received" />
                </p>
            @endif
        </div>
    @endif

    @if ($state === 'paid')
        <p role="status" class="flex items-center gap-2 rounded-control border border-stock/40 bg-stock/10 px-4 py-3 text-small font-medium text-stock">
            <span>{{ $order->paid_at === null ? __('storefront.payment.paid') : __('storefront.payment.paid_with_time', ['time' => $order->paid_at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M').' '.$order->paid_at->copy()->setTimezone($timezone)->format('H:i')]) }}</span>
        </p>
    @elseif ($state === 'window_closed')
        <div role="status" class="flex items-start gap-3 rounded-card border border-line bg-surface p-4">
            {!! $alertIcon !!}
            <p class="text-small text-muted">{{ $order->status === \App\Enums\OrderStatus::AwaitingBalance ? __('storefront.payment.balance_window_closed') : __('storefront.payment.window_closed') }}</p>
        </div>
    @elseif ($state === 'submitted')
        <div wire:poll.15s="checkStatus" role="status" class="flex items-start gap-3 rounded-card border border-line-strong bg-surface p-4">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-info"><rect x="4" y="2.6" width="12" height="14.8" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M7 7h6M7 10.4h6M7 13.8h3.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <div class="min-w-0 flex-1">
                <h3 class="text-small font-medium text-info">{{ __('storefront.payment.checking_title') }}</h3>
                <p class="mt-1 text-small text-muted">{{ __('storefront.payment.checking') }}</p>
            </div>
        </div>
    @elseif ($state === 'rejected')
        @php
            $reasonLines = preg_split('/\R/u', trim((string) $payment->rejected_reason), 2);
            $heading = filled($reasonLines[0]) ? $reasonLines[0] : __('storefront.payment.rejected_generic');
            $newSlip = 'cursor-pointer has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent';
        @endphp
        <div role="alert" class="flex items-start gap-3 rounded-card border border-line-strong bg-surface p-4">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-low"><rect x="4" y="2.6" width="12" height="14.8" rx="1.6" stroke="currentColor" stroke-width="1.6"/><path d="M7 7h6M7 10.4h6M7 13.8h3.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <div class="min-w-0 flex-1">
                <h3 class="text-small font-medium text-low">{{ $heading }}</h3>
                @if (filled($reasonLines[1] ?? null))
                    <p class="mt-1 whitespace-pre-line text-small text-muted">{{ $reasonLines[1] }}</p>
                @endif
                <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false">
                    @if ($canPayShortfall)
                        <button type="button" wire:click="payShortfall" wire:loading.attr="aria-disabled" class="{{ $primaryButton }}">{{ __('storefront.payment.rejected_pay_shortfall') }}</button>
                    @endif
                    {{-- Picking a file opens a fresh payment and attaches the slip to it in one go. --}}
                    <label class="{{ ($canPayShortfall ? $secondaryButton : $primaryButton).' '.$newSlip }}">
                        <span x-text="uploading ? @js(__('storefront.payment.slip_uploading')) : @js(__('storefront.payment.rejected_new_slip'))">{{ __('storefront.payment.rejected_new_slip') }}</span>
                        <input type="file" wire:model="slip" accept="{{ $accept }}" class="sr-only" @error('slip') aria-describedby="slip-error" @enderror>
                    </label>
                </div>
                @unless ($canPayShortfall)
                    <button type="button" wire:click="retryPayment" wire:loading.attr="aria-disabled" class="mt-2 inline-flex min-h-11 items-center text-small text-accent hover:text-ink">{{ __('storefront.payment.show_qr_again') }}</button>
                @endunless
            </div>
        </div>
    @elseif ($state === 'pending')
        @if ($isShortfall)
            <p class="flex items-baseline justify-between gap-4 text-small">
                <span class="text-muted">{{ __('storefront.payment.shortfall') }}</span>
                <x-price :satang="$payment->amount_satang" class="font-medium text-accent" />
            </p>
        @endif

        @if ($payment->method === \App\Enums\PaymentMethod::Promptpay && $payment->qr_payload !== null)
            @if ($qrExpired)
                <div role="status" class="flex items-start gap-3 rounded-card border border-line-strong bg-surface p-4">
                    {!! $alertIcon !!}
                    <div class="min-w-0 flex-1">
                        <h3 class="text-small font-medium text-low">{{ __('storefront.payment.qr_expired') }}</h3>
                        <p class="mt-1 text-small text-muted">{{ __('storefront.payment.qr_expired_hint', ['minutes' => max(1, (int) ceil(now()->diffInSeconds($order->payment_due_at, false) / 60))]) }}</p>
                        <button type="button" wire:click="refreshQr" wire:loading.attr="aria-disabled" class="{{ $primaryButton }} mt-3">{{ __('storefront.payment.qr_refresh') }}</button>
                    </div>
                </div>
            @else
                <x-qr-block
                    :payload="$payment->qr_payload"
                    :amount="$payment->amount_satang"
                    :expires-at="$payment->qr_expires_at"
                    :account="\App\Support\PromptPay::format($promptPayId)"
                    :save-url="route('order.qr', $order->number)"
                />
            @endif
        @elseif ($payment->method === \App\Enums\PaymentMethod::BankTransfer)
            <dl class="flex flex-col gap-2 rounded-card border border-line-strong bg-surface p-4 text-small">
                <div class="flex items-baseline justify-between gap-4"><dt class="text-muted">{{ __('storefront.payment.bank') }}</dt><dd>{{ $bank['name'] }}</dd></div>
                <div class="flex items-baseline justify-between gap-4"><dt class="text-muted">{{ __('storefront.payment.account_name') }}</dt><dd>{{ $bank['account_name'] }}</dd></div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-muted">{{ __('storefront.payment.account_number') }}</dt>
                    <dd class="flex items-center gap-3">
                        <span class="font-medium tabular-nums">{{ $bank['account_number'] }}</span>
                        <button type="button" x-data="copyButton" data-copy="{{ $bank['account_number'] }}" x-on:click="copy()" class="{{ $secondaryButton }} text-label">
                            <span x-text="copied ? @js(__('storefront.payment.copied')) : @js(__('storefront.payment.copy_account'))">{{ __('storefront.payment.copy_account') }}</span>
                        </button>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-t border-line pt-2"><dt class="text-muted">{{ __('storefront.payment.amount') }}</dt><dd class="flex items-center gap-3">
                        <x-price :satang="$payment->amount_satang" class="font-medium text-ink" />
                        <button type="button" x-data="copyButton" data-copy="{{ \App\Support\Money::fromSatang($payment->amount_satang)->toDecimalString() }}" x-on:click="copy()" class="{{ $secondaryButton }} text-label">
                            <span x-text="copied ? @js(__('storefront.payment.copied')) : @js(__('storefront.payment.copy_amount'))">{{ __('storefront.payment.copy_amount') }}</span>
                        </button>
                    </dd></div>
            </dl>
        @endif

        <div
            x-data="{ uploading: false }"
            x-on:livewire-upload-start="uploading = true"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false"
        >
            <label @class(['flex min-h-17 w-full cursor-pointer items-center gap-3 rounded-card border border-dashed bg-sunken px-4 py-3 text-left has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent', 'border-danger' => $errors->has('slip'), 'border-line-strong' => ! $errors->has('slip')])>
                <span class="flex size-9 flex-none items-center justify-center rounded-control bg-raised">
                    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" aria-hidden="true" class="text-muted"><path d="M9 13V4M5.5 7.5 9 4l3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 14.5h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-small font-medium">{{ __('storefront.payment.slip_button') }}</span>
                    <span class="mt-1 block text-small text-muted" x-text="uploading ? @js(__('storefront.payment.slip_uploading')) : @js(__('storefront.payment.slip_hint', ['size' => $slipSize]))">{{ __('storefront.payment.slip_hint', ['size' => $slipSize]) }}</span>
                </span>
                <input type="file" wire:model="slip" accept="{{ $accept }}" class="sr-only" @error('slip') aria-describedby="slip-error" @enderror>
            </label>
        </div>
    @endif

    @error('slip')
        <p id="slip-error" role="alert" class="text-small text-danger">{{ $message }}</p>
    @enderror
    @error('action')
        <p role="alert" class="text-small text-danger">{{ $message }}</p>
    @enderror
</div>
