<?php

namespace App\Filament\Resources\CardSets\Pages;

use App\Filament\Resources\CardSets\CardSetResource;
use App\Models\CardSet;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCardSet extends EditRecord
{
    protected static string $resource = CardSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->color('gray')
                ->outlined()
                ->hidden(fn (CardSet $record): bool => $record->printings()->withTrashed()->exists()),
        ];
    }
}
