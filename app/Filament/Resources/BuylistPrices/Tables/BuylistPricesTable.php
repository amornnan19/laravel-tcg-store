<?php

namespace App\Filament\Resources\BuylistPrices\Tables;

use App\Actions\BulkEditBuylistPrices;
use App\Enums\CardCondition;
use App\Exceptions\BuylistPriceInvalid;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\BuylistPrices\Schemas\BuylistPriceForm;
use App\Models\BuylistPrice;
use App\Models\CardSet;
use App\Models\Game;
use App\Support\Money;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BuylistPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('printing.cardWithTrashed.name_en')
                    ->label(__('admin.fields.card'))
                    ->searchable(),
                TextColumn::make('code')
                    ->label(__('admin.fields.code'))
                    ->state(fn (BuylistPrice $price): string => $price->printing->set->code.' '.$price->printing->number),
                TextColumn::make('printing.language')
                    ->label(__('admin.fields.language'))
                    ->badge(),
                TextColumn::make('condition')
                    ->label(__('admin.fields.condition'))
                    ->badge(),
                TextColumn::make('finish')
                    ->label(__('admin.fields.finish')),
                TextColumn::make('cash_satang')
                    ->label(__('admin.buylist.fields.cash'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->alignEnd(),
                TextColumn::make('credit_satang')
                    ->label(__('admin.buylist.fields.credit'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->alignEnd(),
                TextColumn::make('weekly_quota')
                    ->label(__('admin.buylist.fields.weekly_quota'))
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('accepted_this_week')
                    ->label(__('admin.buylist.fields.accepted_this_week'))
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label(__('admin.buylist.fields.is_active'))
                    ->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['printing.set', 'printing.cardWithTrashed']))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->persistFiltersInSession()
            ->filters([
                SelectFilter::make('game')
                    ->label(__('admin.filters.game'))
                    ->options(fn (): array => Game::query()->orderBy('sort')->pluck('name_th', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('printing.set', fn (Builder $set) => $set->where('game_id', $data['value']))
                        : $query),
                SelectFilter::make('set')
                    ->label(__('admin.filters.set'))
                    ->options(fn (): array => CardSet::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (CardSet $set): array => [$set->getKey() => $set->code.' · '.$set->name_en])
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('printing', fn (Builder $printing) => $printing->where('set_id', $data['value']))
                        : $query),
                SelectFilter::make('condition')
                    ->label(__('admin.fields.condition'))
                    ->options(CardCondition::class),
                TernaryFilter::make('is_active')
                    ->label(__('admin.buylist.filters.active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::editAction(),
                    self::creditBonusAction(),
                ]),
            ]);
    }

    private static function selectedCount(HasTable $livewire): int
    {
        return $livewire->getSelectedTableRecords()->count();
    }

    public static function editAction(): BulkAction
    {
        return BulkAction::make('edit_prices')
            ->label(__('admin.buylist.bulk.edit.label'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->requiresConfirmation()
            ->modalHeading(fn (HasTable $livewire): string => __('admin.buylist.bulk.edit.heading', ['count' => self::selectedCount($livewire)]))
            ->modalDescription(__('admin.buylist.bulk.edit.description'))
            ->deselectRecordsAfterCompletion()
            ->schema([
                MoneyInput::make('cash')
                    ->label(__('admin.buylist.fields.cash'))
                    ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (is_numeric($value) && (float) $value <= 0) {
                            $fail(__('admin.buylist.validation.cash_positive'));
                        }
                    }]),
                MoneyInput::make('credit')
                    ->label(__('admin.buylist.fields.credit')),
                TextInput::make('quota')
                    ->label(__('admin.buylist.fields.weekly_quota'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(2147483647),
                Toggle::make('clear_quota')
                    ->label(__('admin.buylist.fields.clear_quota')),
                Select::make('active')
                    ->label(__('admin.buylist.bulk.edit.active'))
                    ->options([
                        'keep' => __('admin.buylist.bulk.edit.active_keep'),
                        'on' => __('admin.buylist.bulk.edit.active_on'),
                        'off' => __('admin.buylist.bulk.edit.active_off'),
                    ])
                    ->default('keep')
                    ->required(),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action, BulkEditBuylistPrices $edit): void {
                try {
                    $count = $edit->edit(
                        $records,
                        filled($data['cash'] ?? null) ? (int) $data['cash'] : null,
                        filled($data['credit'] ?? null) ? (int) $data['credit'] : null,
                        filled($data['quota'] ?? null) ? (int) $data['quota'] : null,
                        (bool) ($data['clear_quota'] ?? false),
                        match ($data['active'] ?? 'keep') {
                            'on' => true,
                            'off' => false,
                            default => null,
                        },
                    );
                } catch (BuylistPriceInvalid $invalid) {
                    $action->failureNotificationTitle($invalid->getMessage());
                    $action->failure();

                    return;
                }

                $action->successNotificationTitle(__('admin.buylist.bulk.done', ['count' => $count]));
                $action->success();
            });
    }

    public static function creditBonusAction(): BulkAction
    {
        $percent = BuylistPriceForm::bonusPercent();

        return BulkAction::make('credit_bonus')
            ->label(__('admin.buylist.bulk.credit_bonus.label', ['percent' => $percent]))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->requiresConfirmation()
            ->modalHeading(fn (HasTable $livewire): string => __('admin.buylist.bulk.credit_bonus.heading', ['count' => self::selectedCount($livewire)]))
            ->modalDescription(__('admin.buylist.bulk.credit_bonus.description', ['percent' => $percent]))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, BulkAction $action, BulkEditBuylistPrices $edit): void {
                try {
                    $count = $edit->setCreditFromCash($records);
                } catch (BuylistPriceInvalid $invalid) {
                    $action->failureNotificationTitle($invalid->getMessage());
                    $action->failure();

                    return;
                }

                $action->successNotificationTitle(__('admin.buylist.bulk.done', ['count' => $count]));
                $action->success();
            });
    }
}
