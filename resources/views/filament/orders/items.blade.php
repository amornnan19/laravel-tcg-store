@php
    use App\Filament\Resources\Variants\VariantResource;
    use App\Models\Variant;
    use App\Support\Money;

    $order = $getRecord();
    $items = $order->items()->get()->sort(fn ($a, $b) => strcmp($a->set_code, $b->set_code) ?: strnatcmp($a->number, $b->number));
    $variants = Variant::query()
        ->whereIn('id', $items->where('purchasable_type', (new Variant)->getMorphClass())->pluck('purchasable_id')->filter())
        ->get()->keyBy('id');
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-start text-gray-500 dark:border-white/10 dark:text-gray-400">
                <th class="py-2 pe-4 text-start font-medium">{{ __('admin.orders.fields.item') }}</th>
                <th class="w-20 py-2 pe-4 text-end font-medium">{{ __('admin.orders.fields.qty') }}</th>
                <th class="w-32 py-2 text-end font-medium">{{ __('admin.orders.fields.line_total') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($items as $item)
                @php($variant = $item->purchasable_type === (new Variant)->getMorphClass() ? $variants->get($item->purchasable_id) : null)
                <tr>
                    <td class="py-3 pe-4 align-top">
                        @if ($variant !== null)
                            <a href="{{ VariantResource::getUrl('edit', ['record' => $variant]) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $item->card_name }}</a>
                        @else
                            <span class="font-medium">{{ $item->card_name }}</span>
                        @endif
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span class="tabular-nums">{{ strtoupper($item->set_code) }} · {{ $item->number }}</span>
                            <x-filament::badge size="sm">{{ strtoupper($item->language) }}</x-filament::badge>
                            <x-filament::badge size="sm">{{ strtoupper($item->condition) }}</x-filament::badge>
                            <x-filament::badge size="sm">{{ strtoupper($item->finish) }}</x-filament::badge>
                        </div>
                    </td>
                    <td class="py-3 pe-4 text-end align-top tabular-nums">{{ $item->qty }}</td>
                    <td class="py-3 text-end align-top tabular-nums">{{ Money::fromSatang($item->line_total_satang)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
