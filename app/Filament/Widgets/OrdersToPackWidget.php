<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class OrdersToPackWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Order::class);
    }

    protected function getStats(): array
    {
        return [
            Stat::make(
                __('admin.widgets.orders_to_pack'),
                Order::query()->where('status', OrderStatus::Paid->value)->count(),
            )->url(OrderResource::getUrl('index', ['filters' => ['status' => ['value' => OrderStatus::Paid->value]]])),
        ];
    }
}
