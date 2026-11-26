<?php

namespace App\Filament\Resources\SealedProducts;

use App\Filament\Resources\SealedProducts\Pages\CreateSealedProduct;
use App\Filament\Resources\SealedProducts\Pages\EditSealedProduct;
use App\Filament\Resources\SealedProducts\Pages\ListSealedProducts;
use App\Filament\Resources\SealedProducts\Schemas\SealedProductForm;
use App\Filament\Resources\SealedProducts\Tables\SealedProductsTable;
use App\Models\SealedProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SealedProductResource extends Resource
{
    protected static ?string $model = SealedProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.sealed_product.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.sealed_product.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return SealedProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SealedProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSealedProducts::route('/'),
            'create' => CreateSealedProduct::route('/create'),
            'edit' => EditSealedProduct::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
