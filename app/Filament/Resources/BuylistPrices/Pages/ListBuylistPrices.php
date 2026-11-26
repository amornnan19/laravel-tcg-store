<?php

namespace App\Filament\Resources\BuylistPrices\Pages;

use App\Filament\Imports\BuylistPriceImporter;
use App\Filament\Resources\BuylistPrices\BuylistPriceResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListBuylistPrices extends ListRecords
{
    protected static string $resource = BuylistPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(BuylistPriceImporter::class)
                ->label(__('admin.buylist.actions.import')),
            CreateAction::make(),
        ];
    }
}
