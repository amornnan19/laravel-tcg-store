<?php

namespace App\Filament\Resources\Games\Tables;

use App\Models\Game;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class GamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('admin.fields.slug')),
                TextColumn::make('sort')
                    ->label(__('admin.fields.sort'))
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->disabled(fn (Game $record): bool => ! auth()->user()?->can('update', $record)),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
