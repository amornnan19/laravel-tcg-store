<?php

namespace App\Filament\Resources\Preorders\Tables;

use App\Models\Preorder;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PreordersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sealedProduct.name_en')
                    ->label(__('admin.fields.sealed_product'))
                    ->searchable(),
                TextColumn::make('release_on')
                    ->label(__('admin.fields.release_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('cancel_until')
                    ->label(__('admin.fields.cancel_until'))
                    ->date('j M Y'),
                TextColumn::make('reserved_count')
                    ->label(__('admin.fields.preorder_progress'))
                    ->formatStateUsing(fn (int $state, Preorder $record): string => $state.' / '.$record->quota)
                    ->sortable(),
                TextColumn::make('deposit_satang')
                    ->label(__('admin.fields.deposit'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format()),
                TextColumn::make('full_satang')
                    ->label(__('admin.fields.full_price'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format()),
                IconColumn::make('is_open')
                    ->label(__('admin.fields.preorder_open'))
                    ->boolean(),
            ])
            // A product in the trash still names its pre-order.
            ->modifyQueryUsing(fn ($query) => $query->with(['sealedProduct' => fn ($products) => $products->withTrashed()]))
            ->defaultSort('release_on')
            ->filters([
                TernaryFilter::make('is_open')
                    ->label(__('admin.fields.preorder_open')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
