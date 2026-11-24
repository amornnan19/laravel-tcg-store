<?php

namespace App\Filament\Resources\Printings\Pages;

use App\Actions\GenerateVariants;
use App\Filament\Resources\Printings\PrintingResource;
use App\Filament\Resources\Printings\Tables\PrintingsTable;
use App\Models\Printing;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPrinting extends EditRecord
{
    protected static string $resource = PrintingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateVariants')
                ->label(__('admin.actions.generate_variants'))
                ->icon(Heroicon::OutlinedSquaresPlus)
                ->requiresConfirmation()
                ->modalHeading(__('admin.actions.generate_variants_heading'))
                ->modalDescription(__('admin.actions.generate_variants_description'))
                ->modalIcon(Heroicon::OutlinedSquaresPlus)
                ->modalIconColor('primary')
                ->visible(fn (Printing $record): bool => ! $record->trashed())
                ->action(function (Printing $record, GenerateVariants $generateVariants, Action $action): void {
                    // Saves first so unsaved edits are not lost; a validation error stops here and generates nothing.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    $created = $generateVariants->handle($record);

                    $action->successNotificationTitle(__('admin.actions.generate_variants_done', ['count' => $created]))
                        ->success();

                    // The loaded relation is stale now; without this the form refills from it and the next save deletes the new rows.
                    $this->getRecord()->unsetRelation('variants');
                    $record->unsetRelation('variants');
                    $this->refreshFormData(['variants']);
                }),
            PrintingsTable::deleteAction()->color('gray')->outlined(),
            PrintingsTable::restoreAction(),
        ];
    }
}
