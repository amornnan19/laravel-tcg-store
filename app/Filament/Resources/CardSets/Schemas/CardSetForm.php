<?php

namespace App\Filament\Resources\CardSets\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class CardSetForm
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
                TextInput::make('code')
                    ->label(__('admin.fields.code'))
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('game_id', $get('game_id')),
                    ),
                TextInput::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->maxLength(255),
                DatePicker::make('released_on')
                    ->label(__('admin.fields.released_on')),
                TextInput::make('card_count')
                    ->label(__('admin.fields.card_count'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(32767)
                    ->default(0),
                TextInput::make('sort')
                    ->label(__('admin.fields.sort'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(32767)
                    ->default(0),
            ]);
    }
}
