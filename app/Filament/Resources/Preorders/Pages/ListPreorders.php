<?php

namespace App\Filament\Resources\Preorders\Pages;

use App\Filament\Resources\Preorders\PreorderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPreorders extends ListRecords
{
    protected static string $resource = PreorderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
