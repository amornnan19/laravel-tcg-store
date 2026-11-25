<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Printings\PrintingResource;
use App\Models\RestockAlert;
use App\Models\Variant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The restock alerts that fired in the last day and the variants that caused them, most alerts first.
 */
class RestockAlertsFiredWidget extends TableWidget
{
    private const LIMIT = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_staff === true;
    }

    public function table(Table $table): Table
    {
        $since = now()->subDay();

        return $table
            ->heading(__('admin.widgets.restock_alerts_fired'))
            ->description(__('admin.widgets.restock_alerts_count', ['n' => RestockAlert::query()->where('notified_at', '>=', $since)->count()]))
            ->query(
                Variant::query()
                    ->with(['printingWithTrashed.cardWithTrashed', 'printingWithTrashed.set'])
                    ->whereHas('restockAlerts', fn (Builder $alerts) => $alerts->where('notified_at', '>=', $since))
                    ->withCount(['restockAlerts as fired_count' => fn (Builder $alerts) => $alerts->where('notified_at', '>=', $since)])
                    ->orderByDesc('fired_count')
                    ->orderBy('variants.id')
                    ->limit(self::LIMIT)
            )
            ->columns([
                TextColumn::make('card')
                    ->label(__('admin.widgets.restock_card'))
                    ->state(fn (Variant $variant): string => $variant->printingWithTrashed->cardWithTrashed->name_en)
                    ->description(fn (Variant $variant): string => $variant->printingWithTrashed->display_code)
                    ->url(fn (Variant $variant): string => PrintingResource::getUrl('edit', ['record' => $variant->printing_id])),
                TextColumn::make('meta')
                    ->label(__('admin.widgets.restock_variant'))
                    ->state(fn (Variant $variant): string => $variant->printingWithTrashed->language->shortCode().' · '.$variant->conditionFinishLabel()),
                TextColumn::make('fired_count')
                    ->label(__('admin.widgets.restock_count'))
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin.widgets.restock_empty'));
    }
}
