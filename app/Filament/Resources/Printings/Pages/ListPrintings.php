<?php

namespace App\Filament\Resources\Printings\Pages;

use App\Filament\Resources\Printings\PrintingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrintings extends ListRecords
{
    protected static string $resource = PrintingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
