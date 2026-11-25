<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Filament\Pages\PaymentDesk;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class PendingSlipsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Payment::class);
    }

    protected function getStats(): array
    {
        return [
            Stat::make(
                __('admin.widgets.pending_slips'),
                Payment::query()->where('status', PaymentStatus::Submitted->value)->count(),
            )->url(PaymentDesk::getUrl()),
        ];
    }
}
