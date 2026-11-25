@php
    $order = $getRecord();
    $address = $order->address;
@endphp

<dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
    <div>
        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.recipient') }}</dt>
        <dd class="font-medium">{{ $address['recipient'] ?? '' }} · {{ $address['phone'] ?? '' }}</dd>
        <dd class="text-gray-500 dark:text-gray-400">{{ $order->contact_name }} · {{ $order->contact_phone }} · {{ $order->contact_email }}</dd>
    </div>
    <div>
        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.address') }}</dt>
        <dd>{{ $address['line1'] ?? '' }} {{ $address['subdistrict'] ?? '' }} {{ $address['district'] ?? '' }} {{ $address['province'] ?? '' }} {{ $address['postcode'] ?? '' }}</dd>
    </div>
    <div>
        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.courier') }}</dt>
        <dd class="font-medium">{{ $order->courier->label() }}</dd>
    </div>
    <div>
        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.orders.fields.tracking_number') }}</dt>
        <dd>
            @if (filled($order->tracking_number))
                <a href="{{ $order->courier->trackingUrl($order->tracking_number) }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $order->tracking_number }}</a>
            @else
                —
            @endif
        </dd>
    </div>
</dl>
