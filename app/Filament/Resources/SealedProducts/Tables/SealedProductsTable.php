<?php

namespace App\Filament\Resources\SealedProducts\Tables;

use App\Enums\SealedKind;
use App\Models\SealedProduct;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SealedProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label(__('admin.fields.sku'))
                    ->searchable(),
                TextColumn::make('kind')
                    ->label(__('admin.fields.kind'))
                    ->badge(),
                TextColumn::make('game.name_th')
                    ->label(__('admin.fields.game')),
                TextColumn::make('price_satang')
                    ->label(__('admin.fields.price'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::fromSatang($state)->format())
                    ->sortable(),
                TextColumn::make('qty')
                    ->label(__('admin.fields.qty'))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
                IconColumn::make('is_preorder')
                    ->label(__('admin.fields.is_preorder'))
                    ->boolean(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('game'))
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('admin.fields.kind'))
                    ->options(SealedKind::class),
                SelectFilter::make('game_id')
                    ->label(__('admin.filters.game'))
                    ->relationship('game', 'name_th')
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * A delete action whose confirmation names the product and says it can be restored, shared by the row and the edit page.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (SealedProduct $record): string => __('admin.actions.delete_sealed_heading', ['name' => $record->name_en]))
            ->modalDescription(__('admin.actions.delete_sealed_description'));
    }
}
