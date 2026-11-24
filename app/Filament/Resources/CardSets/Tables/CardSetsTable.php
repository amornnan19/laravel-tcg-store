<?php

namespace App\Filament\Resources\CardSets\Tables;

use App\Models\CardSet;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CardSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('game.name_th')
                    ->label(__('admin.fields.game')),
                TextColumn::make('code')
                    ->label(__('admin.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->searchable(),
                TextColumn::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->searchable(),
                TextColumn::make('released_on')
                    ->label(__('admin.fields.released_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('card_count')
                    ->label(__('admin.fields.card_count')),
            ])
            ->modifyQueryUsing(fn ($query) => $query
                ->with('game')
                ->withExists(['printings as has_printings' => fn ($printings) => $printings->withTrashed()]))
            ->defaultSort('sort')
            ->filters([
                SelectFilter::make('game_id')
                    ->label(__('admin.filters.game'))
                    ->relationship('game', 'name_th')
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (CardSet $record): bool => (bool) $record->has_printings),
            ]);
    }
}
