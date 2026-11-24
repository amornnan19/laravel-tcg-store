<?php

namespace App\Filament\Resources\CardSets\Pages;

use App\Filament\Resources\CardSets\CardSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCardSets extends ListRecords
{
    protected static string $resource = CardSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
