<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Models\Card;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('game.name_th')
                    ->label(__('admin.fields.game')),
                TextColumn::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_ja')
                    ->label(__('admin.fields.name_ja'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('card_type')
                    ->label(__('admin.fields.card_type'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('game')->withExists('printings as has_printings'))
            ->filters([
                SelectFilter::make('game_id')
                    ->label(__('admin.filters.game'))
                    ->relationship('game', 'name_th')
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The list loads `has_printings` once for every row; the edit page falls back to one query.
     */
    private static function hasPrintings(Card $card): bool
    {
        return (bool) ($card->getAttribute('has_printings') ?? $card->printings()->exists());
    }

    /**
     * A delete action whose confirmation names the card, shared by the table row and the edit page.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->disabled(fn (Card $record): bool => self::hasPrintings($record))
            ->tooltip(fn (Card $record): ?string => self::hasPrintings($record) ? __('admin.actions.card_has_printings') : null)
            ->modalHeading(fn (Card $record): string => __('admin.actions.delete_card_heading', ['name' => $record->name_en]))
            ->modalDescription(fn (Card $record): string => __('admin.actions.delete_card_description', ['name' => $record->name_en]));
    }
}
