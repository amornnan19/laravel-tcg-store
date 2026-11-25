<?php

namespace App\Filament\Resources\GradedItems\Pages;

use App\Filament\Resources\GradedItems\GradedItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGradedItems extends ListRecords
{
    protected static string $resource = GradedItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
