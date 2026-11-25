<?php

namespace App\Filament\Resources\GradedItems;

use App\Filament\Resources\GradedItems\Pages\CreateGradedItem;
use App\Filament\Resources\GradedItems\Pages\EditGradedItem;
use App\Filament\Resources\GradedItems\Pages\ListGradedItems;
use App\Filament\Resources\GradedItems\Schemas\GradedItemForm;
use App\Filament\Resources\GradedItems\Tables\GradedItemsTable;
use App\Models\GradedItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GradedItemResource extends Resource
{
    protected static ?string $model = GradedItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.graded_item.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.graded_item.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return GradedItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GradedItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGradedItems::route('/'),
            'create' => CreateGradedItem::route('/create'),
            'edit' => EditGradedItem::route('/{record}/edit'),
        ];
    }
}
