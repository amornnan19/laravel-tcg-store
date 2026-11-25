<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * Orders past their deadline that still hold a customer's short payment. Nothing cancels them by itself, so staff must
 * decide: wait for the rest, or cancel and return the money.
 */
class ShortfallOrdersWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Order::class);
    }

    protected function getStats(): array
    {
        return [
            Stat::make(__('admin.widgets.shortfall_orders'), Order::query()->overdueWithShortSlip()->count())
                ->url(OrderResource::getUrl('index', ['filters' => ['overdue_with_money' => ['isActive' => true]]])),
        ];
    }
}
