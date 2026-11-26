<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PreorderPlan;
use App\Models\Order;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.orders.sections.status'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('admin.orders.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                            ->color(fn (OrderStatus $state): string => match ($state->color()) {
                                'low' => 'warning',
                                'info' => 'info',
                                'stock' => 'success',
                                default => 'danger',
                            }),
                        TextEntry::make('cancel_reason')
                            ->label(__('admin.orders.fields.reason'))
                            ->visible(fn (Order $record): bool => filled($record->cancel_reason)),
                        TextEntry::make('refund_reason')
                            ->label(__('admin.orders.fields.reason'))
                            ->visible(fn (Order $record): bool => filled($record->refund_reason)),
                        ViewEntry::make('timeline')
                            ->hiddenLabel()
                            ->view('filament.orders.timeline'),
                    ]),
                Section::make(__('admin.orders.sections.preorder'))
                    ->visible(fn (Order $record): bool => $record->isPreorder())
                    ->schema([
                        TextEntry::make('preorder_plan')
                            ->label(__('admin.orders.fields.preorder_plan'))
                            ->formatStateUsing(fn (?PreorderPlan $state): string => $state?->label() ?? '—'),
                        TextEntry::make('release')
                            ->label(__('admin.orders.fields.release_on'))
                            ->state(fn (Order $record): string => $record->preorder?->releaseAt()->locale('th')->translatedFormat('j M Y') ?? '—'),
                        TextEntry::make('deposit_satang')
                            ->label(__('admin.orders.fields.deposit'))
                            ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format()),
                        TextEntry::make('balance_due_satang')
                            ->label(__('admin.orders.fields.balance_due'))
                            ->formatStateUsing(fn (int $state): string => Money::fromSatang($state)->format()),
                        TextEntry::make('balance_requested_at')
                            ->label(__('admin.orders.fields.balance_requested_at'))
                            ->dateTime('j M Y H:i', config('shop.timezone'))
                            ->placeholder(__('admin.orders.balance_not_requested')),
                        TextEntry::make('balance_overdue')
                            ->hiddenLabel()
                            ->state(fn (): string => __('admin.orders.balance_overdue'))
                            ->color('danger')
                            ->visible(fn (Order $record): bool => $record->status === OrderStatus::AwaitingBalance
                                && $record->balance_requested_at !== null
                                && $record->payment_due_at->lte(now())),
                    ]),
                Section::make(__('admin.orders.sections.payment'))
                    ->schema([
                        ViewEntry::make('payments')
                            ->hiddenLabel()
                            ->view('filament.orders.payments'),
                    ]),
                Section::make(__('admin.orders.sections.items'))
                    ->schema([
                        ViewEntry::make('items')
                            ->hiddenLabel()
                            ->view('filament.orders.items'),
                    ]),
                Section::make(__('admin.orders.sections.shipping'))
                    ->schema([
                        ViewEntry::make('address')
                            ->hiddenLabel()
                            ->view('filament.orders.shipping'),
                    ]),
                Section::make(__('admin.orders.sections.note'))
                    ->schema([
                        TextEntry::make('staff_note')
                            ->hiddenLabel()
                            ->placeholder(__('admin.orders.no_note')),
                    ]),
            ]);
    }
}
