<?php

namespace App\Filament\Resources\SealedProducts\Pages;

use App\Filament\Resources\SealedProducts\SealedProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSealedProducts extends ListRecords
{
    protected static string $resource = SealedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
