<?php

namespace App\Filament\Widgets;

use App\Enums\BuylistStatus;
use App\Filament\Resources\BuylistSubmissions\BuylistSubmissionResource;
use App\Models\BuylistSubmission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * What the buylist desk is waiting on: lists whose cards have not arrived (with the age of the oldest), and lists the
 * customer has to answer.
 */
class BuylistAwaitingCardsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', BuylistSubmission::class);
    }

    protected function getStats(): array
    {
        $waiting = BuylistSubmission::query()->where('status', BuylistStatus::Submitted->value);
        $oldest = (clone $waiting)->oldest('id')->first(['id', 'created_at']);

        return [
            Stat::make(__('admin.widgets.buylist_awaiting_cards'), (clone $waiting)->count())
                ->description($oldest === null
                    ? __('admin.widgets.buylist_none_waiting')
                    : __('admin.widgets.buylist_oldest', ['age' => $oldest->created_at->locale('th')->diffForHumans()]))
                ->url(self::filteredUrl(BuylistStatus::Submitted)),
            Stat::make(__('admin.widgets.buylist_revised'), BuylistSubmission::query()->where('status', BuylistStatus::Revised->value)->count())
                ->url(self::filteredUrl(BuylistStatus::Revised)),
        ];
    }

    private static function filteredUrl(BuylistStatus $status): string
    {
        return BuylistSubmissionResource::getUrl('index', ['filters' => ['status' => ['values' => [$status->value]]]]);
    }
}
