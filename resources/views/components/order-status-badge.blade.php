@props(['status'])

@php
    $colour = match ($status->color()) {
        'low' => 'border-low/40 bg-low/10 text-low',
        'info' => 'border-info/40 bg-info/10 text-info',
        'stock' => 'border-stock/40 bg-stock/10 text-stock',
        'danger' => 'border-danger/40 bg-danger/10 text-danger',
    };
@endphp

<span {{ $attributes->class([$colour, 'inline-flex h-6 items-center rounded-tag border px-2 text-label font-medium']) }}>{{ $status->label() }}</span>
