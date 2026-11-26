<?php

namespace App\Filament\Resources\StoreCreditEntries\Tables;

use App\Enums\StoreCreditReason;
use App\Models\StoreCreditEntry;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StoreCreditEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('admin.credit.fields.user'))
                    ->description(fn (StoreCreditEntry $entry): ?string => $entry->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('reason')
                    ->label(__('admin.credit.fields.reason'))
                    ->badge()
                    ->formatStateUsing(fn (StoreCreditReason $state): string => $state->label())
                    ->color(fn (StoreCreditReason $state): string => match ($state) {
                        StoreCreditReason::Order => 'warning',
                        StoreCreditReason::Adjustment => 'gray',
                        default => 'success',
                    }),
                TextColumn::make('delta_satang')
                    ->label(__('admin.credit.fields.delta'))
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '−').Money::fromSatang(abs($state))->format())
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->alignEnd(),
                TextColumn::make('balance_after_satang')
                    ->label(__('admin.credit.fields.balance_after'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->alignEnd(),
                TextColumn::make('source')
                    ->label(__('admin.credit.fields.source'))
                    ->state(fn (StoreCreditEntry $entry): ?string => $entry->source?->number ?? $entry->note),
                TextColumn::make('staff.name')
                    ->label(__('admin.credit.fields.staff'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('admin.credit.fields.created_at'))
                    ->dateTime('j M Y H:i', config('shop.timezone'))
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'staff', 'source']))
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('reason')
                    ->label(__('admin.credit.fields.reason'))
                    ->options(collect(StoreCreditReason::cases())->mapWithKeys(fn (StoreCreditReason $reason): array => [$reason->value => $reason->label()])->all()),
                SelectFilter::make('user_id')
                    ->label(__('admin.credit.fields.user'))
                    ->relationship('user', 'name')
                    ->searchable(),
            ])
            ->emptyStateHeading(__('admin.credit.empty'));
    }
}
