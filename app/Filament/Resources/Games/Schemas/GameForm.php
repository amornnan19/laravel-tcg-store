<?php

namespace App\Filament\Resources\Games\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GameForm
{
    /**
     * The facet keys a game may declare, from the Filters table in `docs/search.md`.
     *
     * @var list<string>
     */
    public const FACETS = ['rarity', 'card_type', 'color', 'attribute', 'language'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('admin.fields.slug'))
                    ->helperText(__('admin.hints.slug'))
                    ->visibleOn('edit')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('sort')
                    ->label(__('admin.fields.sort'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(32767)
                    ->default(0),
                Toggle::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->default(true),
                CheckboxList::make('filters')
                    ->label(__('admin.fields.filters'))
                    ->options(collect(self::FACETS)->mapWithKeys(
                        fn (string $facet): array => [$facet => __('admin.facets.'.$facet)],
                    )->all())
                    ->default([])
                    ->columns(2),
            ]);
    }
}
