@php
    use App\Enums\PaymentStatus;
    use App\Support\Money;

    $order = $getRecord();
    $payments = $order->payments()->orderByDesc('id')->get();
    $paymentColor = fn (PaymentStatus $status): string => match ($status) {
        PaymentStatus::Submitted => 'warning',
        PaymentStatus::Confirmed => 'success',
        PaymentStatus::Rejected, PaymentStatus::Refunded => 'danger',
        default => 'gray',
    };
@endphp

<div class="flex flex-col gap-4 text-sm">
    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-3">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.total') }}</dt>
            <dd class="text-lg font-semibold tabular-nums">{{ Money::fromSatang($order->total_satang)->format() }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.received') }}</dt>
            <dd class="text-lg font-semibold tabular-nums">{{ Money::fromSatang($order->receivedSatang())->format() }}</dd>
        </div>
        @if ($order->refunded_satang > 0)
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.refunded') }}</dt>
                <dd class="text-lg font-semibold tabular-nums text-danger-600 dark:text-danger-400">{{ Money::fromSatang($order->refunded_satang)->format() }}</dd>
            </div>
        @endif
    </dl>

    @foreach ($payments as $payment)
        <div class="grid gap-4 rounded-lg border border-gray-200 p-4 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] dark:border-white/10">
            <div>
                @if (filled($payment->slip_path))
                    @include('filament.partials.slip', ['payment' => $payment])
                @else
                    <p class="text-gray-400 dark:text-gray-500">—</p>
                @endif
            </div>
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ $payment->method->label() }}</span>
                    <x-filament::badge :color="$paymentColor($payment->status)">{{ $payment->status->label() }}</x-filament::badge>
                </div>
                <p class="text-base font-semibold tabular-nums">{{ Money::fromSatang($payment->amount_satang)->format() }}</p>
                @if (filled($payment->rejected_reason))
                    <p class="whitespace-pre-line text-gray-500 dark:text-gray-400">{{ $payment->rejected_reason }}</p>
                @endif
                @if ($payment->slip_amount_satang !== null)
                    <p class="text-gray-500 dark:text-gray-400">{{ __('admin.payment.slip_amount') }}: <span class="tabular-nums">{{ Money::fromSatang($payment->slip_amount_satang)->format() }}</span></p>
                @endif
                @if (filled($payment->slip_ref))
                    <p class="text-gray-500 dark:text-gray-400">{{ __('admin.payment.slip_ref') }}: {{ $payment->slip_ref }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
