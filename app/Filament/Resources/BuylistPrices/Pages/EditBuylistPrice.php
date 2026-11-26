<?php

namespace App\Filament\Resources\BuylistPrices\Pages;

use App\Filament\Resources\BuylistPrices\BuylistPriceResource;
use App\Models\BuylistPrice;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Locked;

class EditBuylistPrice extends EditRecord
{
    protected static string $resource = BuylistPriceResource::class;

    /**
     * The editable columns as they were when the form was last filled or saved. The importer and the bulk actions write
     * the same row, so a save from a stale page is refused. `accepted_this_week` is left out: customers move it all day.
     */
    #[Locked]
    public string $priceFingerprint = '';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterFill(): void
    {
        $this->priceFingerprint = $this->currentFingerprint();
    }

    protected function beforeSave(): void
    {
        // Runs inside the save transaction, before the record is written.
        if ($this->priceFingerprint === $this->currentFingerprint(lockRow: true)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('admin.notifications.buylist_price_changed_elsewhere'))
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    protected function afterSave(): void
    {
        $this->priceFingerprint = $this->currentFingerprint();
    }

    private function currentFingerprint(bool $lockRow = false): string
    {
        $row = BuylistPrice::query()
            ->whereKey($this->getRecord()->getKey())
            ->when($lockRow, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'printing_id', 'condition', 'finish', 'cash_satang', 'credit_satang', 'weekly_quota', 'is_active']);

        return hash('sha256', json_encode($row?->getRawOriginal(), JSON_THROW_ON_ERROR));
    }
}
