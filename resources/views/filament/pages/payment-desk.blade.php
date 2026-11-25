@php
    use App\Support\Money;

    $payments = $this->submittedPayments();
    $timezone = config('shop.timezone');
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @forelse ($payments as $payment)
            <x-filament::section wire:key="payment-{{ $payment->id }}">
                <div class="grid gap-6 md:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                    <div>
                        @include('filament.partials.slip', ['payment' => $payment])
                    </div>

                    <div class="flex flex-col gap-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $payment->order]) }}" class="text-lg font-semibold tabular-nums text-primary-600 hover:underline dark:text-primary-400">{{ $payment->order->number }}</a>
                            <x-filament::badge color="warning">{{ $payment->status->label() }}</x-filament::badge>
                        </div>

                        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.desk.customer') }}</dt>
                                <dd class="font-medium">{{ $payment->order->contact_name }}</dd>
                                <dd class="text-gray-500 dark:text-gray-400">{{ $payment->order->contact_phone }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.desk.method') }}</dt>
                                <dd class="font-medium">{{ $payment->method->label() }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.desk.due') }}</dt>
                                <dd class="text-xl font-semibold tabular-nums">{{ Money::fromSatang($payment->amount_satang)->format() }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.desk.submitted_at') }}</dt>
                                <dd class="tabular-nums">{{ $payment->submitted_at?->copy()->setTimezone($timezone)->format('j M Y H:i') }}</dd>
                            </div>
                        </dl>

                        @if ($payment->shortfallOf)
                            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                                <p class="font-medium">{{ __('admin.desk.earlier_short') }}</p>
                                <p class="text-gray-500 dark:text-gray-400">{{ __('admin.payment.slip_amount') }}: <span class="tabular-nums">{{ Money::fromSatang((int) $payment->shortfallOf->slip_amount_satang)->format() }}</span></p>
                            </div>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            {{ ($this->confirmAction)(['payment' => $payment->id]) }}
                            {{ ($this->rejectAction)(['payment' => $payment->id]) }}
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.desk.empty') }}</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
