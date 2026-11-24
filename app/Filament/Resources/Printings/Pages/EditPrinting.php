<?php

namespace App\Filament\Resources\Printings\Pages;

use App\Actions\GenerateVariants;
use App\Filament\Resources\Printings\PrintingResource;
use App\Filament\Resources\Printings\Tables\PrintingsTable;
use App\Models\Printing;
use App\Models\Variant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

class EditPrinting extends EditRecord
{
    protected static string $resource = PrintingResource::class;

    /**
     * What the printing's variants looked like when the form was last filled or saved. The repeater
     * rewrites every row from this page's state, so a save is refused when the database has moved on.
     */
    #[Locked]
    public string $variantsFingerprint = '';

    protected function afterFill(): void
    {
        $this->rememberVariantsFingerprint();
    }

    protected function beforeSave(): void
    {
        // Runs inside the save transaction, before the repeater writes anything.
        if ($this->variantsFingerprint === $this->currentVariantsFingerprint(lockRows: true)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('admin.notifications.variants_changed_elsewhere'))
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    protected function afterSave(): void
    {
        $this->rememberVariantsFingerprint();
    }

    private function rememberVariantsFingerprint(): void
    {
        $this->variantsFingerprint = $this->currentVariantsFingerprint();
    }

    /**
     * The id set and the editable columns of the variants, with each row's updated_at. The columns
     * are included because updated_at only has one-second resolution. The window between mounting the
     * page and the first save is accepted; inside the save the rows are locked on databases that can.
     */
    private function currentVariantsFingerprint(bool $lockRows = false): string
    {
        $rows = Variant::query()
            ->where('printing_id', $this->getRecord()->getKey())
            ->orderBy('id')
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'updated_at', 'price_satang', 'compare_at_satang', 'qty', 'has_real_photo', 'note_th']);

        return hash('sha256', json_encode($rows->map->getRawOriginal()->all(), JSON_THROW_ON_ERROR));
    }

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

                    // A save refused as stale returns quietly; do not generate on top of a page that is out of date.
                    if ($this->variantsFingerprint !== $this->currentVariantsFingerprint()) {
                        $action->cancel(shouldRollBackDatabaseTransaction: true);
                    }

                    $created = $generateVariants->handle($record);

                    $action->successNotificationTitle(__('admin.actions.generate_variants_done', ['count' => $created]))
                        ->success();

                    // The loaded relation is stale now; without this the form refills from it and the next save deletes the new rows.
                    $this->getRecord()->unsetRelation('variants');
                    $record->unsetRelation('variants');
                    $this->refreshFormData(['variants']);
                    $this->rememberVariantsFingerprint();
                }),
            PrintingsTable::deleteAction()->color('gray')->outlined(),
            PrintingsTable::restoreAction(),
        ];
    }
}
