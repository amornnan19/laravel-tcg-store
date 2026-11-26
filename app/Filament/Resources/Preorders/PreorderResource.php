<?php

namespace App\Filament\Resources\Preorders;

use App\Filament\Resources\Preorders\Pages\CreatePreorder;
use App\Filament\Resources\Preorders\Pages\EditPreorder;
use App\Filament\Resources\Preorders\Pages\ListPreorders;
use App\Filament\Resources\Preorders\Schemas\PreorderForm;
use App\Filament\Resources\Preorders\Tables\PreordersTable;
use App\Models\Preorder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PreorderResource extends Resource
{
    protected static ?string $model = Preorder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 8;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.preorder.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.preorder.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return PreorderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PreordersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPreorders::route('/'),
            'create' => CreatePreorder::route('/create'),
            'edit' => EditPreorder::route('/{record}/edit'),
        ];
    }
}
