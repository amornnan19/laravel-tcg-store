<?php

namespace App\Filament\Resources\SealedProducts\Pages;

use App\Filament\Resources\SealedProducts\SealedProductResource;
use App\Filament\Resources\SealedProducts\Tables\SealedProductsTable;
use App\Models\SealedProduct;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Locked;

class EditSealedProduct extends EditRecord
{
    protected static string $resource = SealedProductResource::class;

    /**
     * The columns an order, a restock or another admin screen may change under an open form, as they were when the
     * form was last filled or saved. A save from a stale page would put an old quantity or price back, so it is refused.
     */
    #[Locked]
    public string $sealedProductFingerprint = '';

    protected function getHeaderActions(): array
    {
        return [
            SealedProductsTable::deleteAction()->color('gray')->outlined(),
            RestoreAction::make(),
        ];
    }

    protected function afterFill(): void
    {
        $this->sealedProductFingerprint = $this->currentFingerprint();
    }

    protected function beforeSave(): void
    {
        // Runs inside the save transaction, before the record is written.
        if ($this->sealedProductFingerprint === $this->currentFingerprint(lockRow: true)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('admin.notifications.sealed_changed_elsewhere'))
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    protected function afterSave(): void
    {
        $this->sealedProductFingerprint = $this->currentFingerprint();
    }

    private function currentFingerprint(bool $lockRow = false): string
    {
        $row = SealedProduct::withTrashed()
            ->whereKey($this->getRecord()->getKey())
            ->when($lockRow, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'updated_at', 'price_satang', 'qty', 'is_active', 'is_preorder']);

        return hash('sha256', json_encode($row?->getRawOriginal(), JSON_THROW_ON_ERROR));
    }
}
