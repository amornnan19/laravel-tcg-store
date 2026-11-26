<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.orders.fields.number'))
                    ->searchable(),
                TextColumn::make('placed_at')
                    ->label(__('admin.orders.fields.placed_at'))
                    ->dateTime('j M Y H:i', config('shop.timezone'))
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->label(__('admin.orders.fields.contact_name'))
                    ->searchable(),
                TextColumn::make('total_satang')
                    ->label(__('admin.orders.fields.total'))
                    ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format())
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.orders.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => match ($state->color()) {
                        'low' => 'warning',
                        'info' => 'info',
                        'stock' => 'success',
                        default => 'danger',
                    }),
                TextColumn::make('latestPayment.status')
                    ->label(__('admin.orders.fields.payment_status'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => match ($state) {
                        PaymentStatus::Submitted => 'warning',
                        PaymentStatus::Confirmed => 'success',
                        PaymentStatus::Rejected, PaymentStatus::Refunded => 'danger',
                        default => 'gray',
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestPayment'))
            ->defaultSort('placed_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.orders.fields.status'))
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('kind')
                    ->label(__('admin.orders.fields.kind'))
                    ->options(collect(OrderKind::cases())->mapWithKeys(fn (OrderKind $kind): array => [$kind->value => $kind->label()])->all()),
                Filter::make('overdue_balance')
                    ->label(__('admin.orders.filters.overdue_balance'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdueBalance()),
                Filter::make('overdue_with_money')
                    ->label(__('admin.orders.filters.overdue_with_money'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdueWithShortSlip()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]));
    }
}
