<?php

namespace App\Filament\Resources\Printings\Tables;

use App\Enums\Language;
use App\Filament\Resources\Printings\PrintingResource;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrintingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('set.code')
                    ->label(__('admin.fields.set'))
                    ->sortable(),
                TextColumn::make('number')
                    ->label(__('admin.fields.number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cardWithTrashed.name_en')
                    ->label(__('admin.fields.card'))
                    ->searchable(),
                TextColumn::make('language')
                    ->label(__('admin.fields.language'))
                    ->badge(),
                TextColumn::make('rarity_code')
                    ->label(__('admin.fields.rarity_code'))
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                IconColumn::make('has_foil')
                    ->label(__('admin.fields.has_foil'))
                    ->boolean(),
                TextColumn::make('variants_count')
                    ->label(__('admin.fields.variants_count'))
                    ->numeric(),
                TextColumn::make('unpriced_variants_count')
                    ->label(__('admin.fields.unpriced_variants_count'))
                    ->numeric(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['cardWithTrashed', 'set'])
                ->withCount([
                    'variants',
                    'variants as unpriced_variants_count' => fn (Builder $variants) => $variants->whereNull('price_satang'),
                ]))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy(CardSet::query()->select('code')->whereColumn('sets.id', 'printings.set_id'))
                ->orderByRaw('length(printings.number)')
                ->orderBy('printings.number'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->persistFiltersInSession()
            ->filters([
                SelectFilter::make('game')
                    ->label(__('admin.filters.game'))
                    ->options(fn (): array => Game::query()->orderBy('sort')->pluck('name_th', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('set', fn (Builder $set) => $set->where('game_id', $data['value']))
                        : $query),
                SelectFilter::make('set_id')
                    ->label(__('admin.filters.set'))
                    ->relationship('set', 'name_en')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('language')
                    ->label(__('admin.filters.language'))
                    ->options(Language::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
                self::restoreAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Restore is refused while the printing's card is still trashed: restore the card first.
     */
    public static function restoreAction(): RestoreAction
    {
        return RestoreAction::make()
            ->disabled(fn (Printing $record): bool => $record->cardWithTrashed?->trashed() === true)
            ->tooltip(fn (Printing $record): ?string => $record->cardWithTrashed?->trashed() === true ? __('admin.actions.restore_card_first') : null);
    }

    /**
     * A delete action whose confirmation names the printing, shared by the table row and the edit page.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Printing $record): string => __('admin.actions.delete_printing_heading', ['name' => PrintingResource::getRecordTitle($record)]))
            ->modalDescription(fn (Printing $record): string => __('admin.actions.delete_printing_description', ['name' => PrintingResource::getRecordTitle($record)]));
    }
}
