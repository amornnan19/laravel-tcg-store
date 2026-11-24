<?php

namespace App\Filament\Resources\CardSets;

use App\Filament\Resources\CardSets\Pages\CreateCardSet;
use App\Filament\Resources\CardSets\Pages\EditCardSet;
use App\Filament\Resources\CardSets\Pages\ListCardSets;
use App\Filament\Resources\CardSets\Schemas\CardSetForm;
use App\Filament\Resources\CardSets\Tables\CardSetsTable;
use App\Models\CardSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CardSetResource extends Resource
{
    protected static ?string $model = CardSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.set.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.set.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CardSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardSets::route('/'),
            'create' => CreateCardSet::route('/create'),
            'edit' => EditCardSet::route('/{record}/edit'),
        ];
    }
}
