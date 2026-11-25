<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
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
