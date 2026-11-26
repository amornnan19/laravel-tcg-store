<?php

namespace App\Filament\Resources\BuylistPrices;

use App\Filament\Resources\BuylistPrices\Pages\CreateBuylistPrice;
use App\Filament\Resources\BuylistPrices\Pages\EditBuylistPrice;
use App\Filament\Resources\BuylistPrices\Pages\ListBuylistPrices;
use App\Filament\Resources\BuylistPrices\Schemas\BuylistPriceForm;
use App\Filament\Resources\BuylistPrices\Tables\BuylistPricesTable;
use App\Models\BuylistPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class BuylistPriceResource extends Resource
{
    protected static ?string $model = BuylistPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.buylist');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.buylist_price.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.buylist_price.plural');
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof BuylistPrice ? BuylistPriceForm::printingLabel($record->printing->loadMissing(['set', 'cardWithTrashed'])) : null;
    }

    public static function form(Schema $schema): Schema
    {
        return BuylistPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BuylistPricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuylistPrices::route('/'),
            'create' => CreateBuylistPrice::route('/create'),
            'edit' => EditBuylistPrice::route('/{record}/edit'),
        ];
    }
}
