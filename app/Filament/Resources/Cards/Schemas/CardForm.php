<?php

namespace App\Filament\Resources\Cards\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('game_id')
                    ->label(__('admin.fields.game'))
                    ->relationship('game', 'name_th')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_ja')
                    ->label(__('admin.fields.name_ja'))
                    ->maxLength(255),
                TextInput::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->maxLength(255),
                TextInput::make('card_type')
                    ->label(__('admin.fields.card_type'))
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('admin.fields.slug'))
                    ->helperText(__('admin.hints.slug'))
                    ->visibleOn('edit')
                    ->disabled()
                    ->dehydrated(false),
                KeyValue::make('traits')
                    ->label(__('admin.fields.traits'))
                    ->keyLabel(__('admin.fields.traits_key'))
                    ->valueLabel(__('admin.fields.traits_value'))
                    ->default([])
                    ->columnSpanFull(),
            ]);
    }
}
