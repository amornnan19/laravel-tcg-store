<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\VariantResource;
use App\Models\Variant;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Locked;

class EditVariant extends EditRecord
{
    protected static string $resource = VariantResource::class;

    /**
     * The editable columns of the variant as they were when the form was last filled or saved. The
     * spreadsheet and the Printing page write the same row, so a save from a stale page is refused.
     */
    #[Locked]
    public string $variantFingerprint = '';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterFill(): void
    {
        $this->variantFingerprint = $this->currentVariantFingerprint();
    }

    protected function beforeSave(): void
    {
        // Runs inside the save transaction, before the record is written.
        if ($this->variantFingerprint === $this->currentVariantFingerprint(lockRow: true)) {
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
        $this->variantFingerprint = $this->currentVariantFingerprint();
    }

    /**
     * The editable columns with updated_at, which only has one-second resolution. The window between
     * mounting the page and the first save is accepted; inside the save the row is locked on
     * databases that can.
     */
    private function currentVariantFingerprint(bool $lockRow = false): string
    {
        $row = Variant::query()
            ->whereKey($this->getRecord()->getKey())
            ->when($lockRow, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'updated_at', 'price_satang', 'compare_at_satang', 'qty', 'has_real_photo', 'note_th']);

        return hash('sha256', json_encode($row?->getRawOriginal(), JSON_THROW_ON_ERROR));
    }
}
