<?php

namespace App\Filament\Resources\Variants\Schemas;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\Variant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label(__('admin.fields.sku'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('printing_title')
                    ->label(__('admin.models.printing.singular'))
                    ->disabled()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (TextInput $component, ?Variant $record): void {
                        $printing = $record?->printingWithTrashed()->with(['set', 'cardWithTrashed'])->first();

                        $component->state($printing === null ? null : $printing->set->code.' '.$printing->number.' '.$printing->cardWithTrashed->name_en);
                    }),
                // Condition and finish identify the variant (SKU, unique index), so they are shown but never edited.
                Select::make('condition')
                    ->label(__('admin.fields.condition'))
                    ->options(CardCondition::class)
                    ->disabled()
                    ->dehydrated(false),
                Select::make('finish')
                    ->label(__('admin.fields.finish'))
                    ->options(Finish::class)
                    ->disabled()
                    ->dehydrated(false),
                MoneyInput::make('price_satang')
                    ->label(__('admin.fields.price')),
                MoneyInput::make('compare_at_satang')
                    ->label(__('admin.fields.compare_at')),
                TextInput::make('qty')
                    ->label(__('admin.fields.qty'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(2147483647)
                    ->default(0),
                TextInput::make('note_th')
                    ->label(__('admin.fields.note_th'))
                    ->maxLength(255),
                Toggle::make('has_real_photo')
                    ->label(__('admin.fields.has_real_photo')),
            ]);
    }
}
