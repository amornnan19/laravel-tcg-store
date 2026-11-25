@php
    $order = $getRecord();
    $timezone = config('shop.timezone');
@endphp

<ol class="flex flex-col gap-4 border-s border-gray-200 ps-5 dark:border-white/10">
    @foreach ($order->timeline() as $step)
        <li class="relative flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 text-sm">
            <span class="absolute -start-[1.6rem] top-1.5 size-2.5 rounded-full {{ $step['at'] === null ? 'bg-gray-300 dark:bg-white/20' : 'bg-primary-500' }}"></span>
            <span class="{{ $step['at'] === null ? 'text-gray-400 dark:text-gray-500' : 'font-medium' }}">{{ __('storefront.order.'.$step['key']) }}</span>
            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $step['at'] === null ? '—' : $step['at']->copy()->setTimezone($timezone)->format('j M Y H:i') }}</span>
        </li>
    @endforeach
</ol>
