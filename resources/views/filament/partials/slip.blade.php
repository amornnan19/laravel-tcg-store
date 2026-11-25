@php
    $slipExists = filled($payment->slip_path) && \Illuminate\Support\Facades\Storage::disk('local')->exists($payment->slip_path);
    $isImage = in_array(strtolower(pathinfo((string) $payment->slip_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
@endphp

@if (! $slipExists)
    <p class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-white/20 dark:text-gray-400">{{ __('admin.payment.slip_missing') }}</p>
@elseif ($isImage)
    <a href="{{ route('admin.slip', $payment) }}" target="_blank" rel="noopener" title="{{ __('admin.payment.slip_open') }}" class="inline-block max-w-full overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
        <img src="{{ route('admin.slip', $payment) }}" alt="{{ __('admin.payment.slip') }}" loading="lazy" class="block max-h-80 w-auto max-w-full object-contain">
    </a>
@else
    <a href="{{ route('admin.slip', $payment) }}" download class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('admin.payment.slip_download') }}</a>
@endif
