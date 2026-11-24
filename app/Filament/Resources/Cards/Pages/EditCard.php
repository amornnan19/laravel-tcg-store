<?php

namespace App\Filament\Resources\Cards\Pages;

use App\Filament\Resources\Cards\CardResource;
use App\Filament\Resources\Cards\Tables\CardsTable;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCard extends EditRecord
{
    protected static string $resource = CardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CardsTable::deleteAction()->color('gray')->outlined(),
            RestoreAction::make(),
        ];
    }
}
