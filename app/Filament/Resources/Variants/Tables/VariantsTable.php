<?php

namespace App\Filament\Resources\Variants\Tables;

use App\Actions\BulkEditVariants;
use App\Enums\CardCondition;
use App\Enums\Language;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\Printings\Schemas\PrintingForm;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Variant;
use App\Support\Money;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class VariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('printingWithTrashed.set.code')
                    ->label(__('admin.fields.set')),
                TextColumn::make('printingWithTrashed.number')
                    ->label(__('admin.fields.number')),
                TextColumn::make('printingWithTrashed.cardWithTrashed.name_en')
                    ->label(__('admin.fields.card'))
                    ->searchable(),
                TextColumn::make('sku')
                    ->label(__('admin.fields.sku'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('printingWithTrashed.language')
                    ->label(__('admin.fields.language'))
                    ->badge(),
                TextColumn::make('printingWithTrashed.rarity_code')
                    ->label(__('admin.fields.rarity_code'))
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('condition')
                    ->label(__('admin.fields.condition'))
                    ->badge(),
                TextColumn::make('finish')
                    ->label(__('admin.fields.finish')),
                TextInputColumn::make('price_satang')
                    ->label(__('admin.fields.price'))
                    ->authorize('update')
                    ->inputMode('decimal')
                    ->placeholder('-')
                    ->getStateUsing(fn (Variant $record): ?string => $record->price_satang === null
                        ? null
                        : Money::fromSatang($record->price_satang)->toBahtString())
                    ->rules([self::moneyRule()])
                    ->updateStateUsing(function (Variant $record, ?string $state): ?string {
                        $record->update([
                            'price_satang' => blank($state) ? null : Money::fromBaht(trim($state))->satang(),
                        ]);

                        return $state;
                    }),
                TextInputColumn::make('qty')
                    ->label(__('admin.fields.qty'))
                    ->authorize('update')
                    ->type('number')
                    ->inputMode('numeric')
                    ->rules(['required', 'integer', 'min:0', 'max:'.BulkEditVariants::MAX_QTY]),
                IconColumn::make('has_real_photo')
                    ->label(__('admin.fields.has_real_photo'))
                    ->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['printingWithTrashed.set', 'printingWithTrashed.cardWithTrashed']))
            ->defaultSort(fn (Builder $query): Builder => self::orderLikeASetList($query))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->persistFiltersInSession()
            ->filters([
                SelectFilter::make('game')
                    ->label(__('admin.filters.game'))
                    ->options(fn (): array => Game::query()->orderBy('sort')->pluck('name_th', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('printingWithTrashed.set', fn (Builder $set) => $set->where('game_id', $data['value']))
                        : $query),
                SelectFilter::make('set')
                    ->label(__('admin.filters.set'))
                    ->options(fn (): array => CardSet::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (CardSet $set): array => [$set->getKey() => $set->code.' · '.$set->name_en])
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('printingWithTrashed', fn (Builder $printing) => $printing->where('set_id', $data['value']))
                        : $query),
                SelectFilter::make('language')
                    ->label(__('admin.filters.language'))
                    ->options(Language::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('printingWithTrashed', fn (Builder $printing) => $printing->where('language', $data['value']))
                        : $query),
                SelectFilter::make('rarity')
                    ->label(__('admin.fields.rarity_code'))
                    ->options(collect(PrintingForm::RARITY_CODES)->mapWithKeys(
                        fn (string $code): array => [$code => strtoupper($code)],
                    )->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('printingWithTrashed', fn (Builder $printing) => $printing->where('rarity_code', $data['value']))
                        : $query),
                SelectFilter::make('condition')
                    ->label(__('admin.fields.condition'))
                    ->options(CardCondition::class),
                Filter::make('unpriced')
                    ->label(__('admin.filters.unpriced'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('price_satang')),
                Filter::make('out_of_stock')
                    ->label(__('admin.filters.out_of_stock'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('qty', 0)),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::setPriceAction(),
                    self::adjustPriceAction(),
                    self::setQtyAction(),
                    self::addQtyAction(),
                    self::markRealPhotoAction(),
                ]),
            ]);
    }

    /**
     * Set code, then card number in natural order, then NM to DMG with normal before foil.
     *
     * @param  Builder<Variant>  $query
     * @return Builder<Variant>
     */
    private static function orderLikeASetList(Builder $query): Builder
    {
        $conditionOrder = collect(CardCondition::cases())
            ->map(fn (CardCondition $condition): string => "when '{$condition->value}' then {$condition->sort()}")
            ->implode(' ');

        return $query
            ->orderByRaw('(select sets.code from sets inner join printings on printings.set_id = sets.id where printings.id = variants.printing_id)')
            ->orderByRaw('(select length(printings.number) from printings where printings.id = variants.printing_id)')
            ->orderByRaw('(select printings.number from printings where printings.id = variants.printing_id)')
            ->orderByRaw("case variants.condition {$conditionOrder} else 99 end")
            ->orderByRaw("case variants.finish when 'normal' then 1 else 2 end");
    }

    private static function moneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            try {
                Money::fromBaht(trim((string) $value));
            } catch (InvalidArgumentException) {
                $fail(__('admin.validation.money'));
            }
        };
    }

    /**
     * "Selected N" for the confirmation heading.
     */
    private static function selectedCount(HasTable $livewire): int
    {
        return $livewire->getSelectedTableRecords()->count();
    }

    private static function bulkAction(string $name): BulkAction
    {
        return BulkAction::make($name)
            ->label(__("admin.bulk.{$name}.label"))
            ->requiresConfirmation()
            ->modalHeading(fn (HasTable $livewire): string => __("admin.bulk.{$name}.heading", ['count' => self::selectedCount($livewire)]))
            ->modalDescription(__("admin.bulk.{$name}.description"))
            ->deselectRecordsAfterCompletion();
    }

    private static function done(BulkAction $action, int $count): void
    {
        $action->successNotificationTitle(__('admin.bulk.done', ['count' => $count]));
        $action->success();
    }

    public static function setPriceAction(): BulkAction
    {
        return self::bulkAction('set_price')
            ->icon(Heroicon::OutlinedBanknotes)
            ->schema([
                MoneyInput::make('price')
                    ->label(__('admin.fields.price'))
                    ->required(),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action, BulkEditVariants $edit): void {
                self::done($action, $edit->setPrice($records, Money::fromSatang((int) $data['price'])));
            });
    }

    public static function adjustPriceAction(): BulkAction
    {
        return self::bulkAction('adjust_price')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->schema([
                TextInput::make('percent')
                    ->label(__('admin.fields.percent'))
                    ->helperText(__('admin.hints.percent'))
                    ->suffix('%')
                    ->inputMode('decimal')
                    ->required()
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            try {
                                BulkEditVariants::basisPoints((string) $value);
                            } catch (InvalidArgumentException) {
                                $fail(__('admin.validation.percent'));
                            }
                        },
                    ]),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action, BulkEditVariants $edit): void {
                self::done($action, $edit->adjustPriceByPercent($records, (string) $data['percent']));
            });
    }

    public static function setQtyAction(): BulkAction
    {
        return self::bulkAction('set_qty')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->schema([
                TextInput::make('qty')
                    ->label(__('admin.fields.qty'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(BulkEditVariants::MAX_QTY),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action, BulkEditVariants $edit): void {
                self::done($action, $edit->setQty($records, (int) $data['qty']));
            });
    }

    public static function addQtyAction(): BulkAction
    {
        return self::bulkAction('add_qty')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->schema([
                TextInput::make('delta')
                    ->label(__('admin.fields.qty_delta'))
                    ->helperText(__('admin.hints.qty_delta'))
                    ->required()
                    ->integer()
                    ->minValue(-BulkEditVariants::MAX_QTY)
                    ->maxValue(BulkEditVariants::MAX_QTY),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action, BulkEditVariants $edit): void {
                self::done($action, $edit->addQty($records, (int) $data['delta']));
            });
    }

    public static function markRealPhotoAction(): BulkAction
    {
        return self::bulkAction('mark_real_photo')
            ->icon(Heroicon::OutlinedCamera)
            ->action(function (Collection $records, BulkAction $action, BulkEditVariants $edit): void {
                self::done($action, $edit->markRealPhotoTaken($records));
            });
    }
}
