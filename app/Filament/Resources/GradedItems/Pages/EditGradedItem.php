<?php

namespace App\Filament\Resources\GradedItems\Pages;

use App\Exceptions\GradedNotListable;
use App\Filament\Resources\GradedItems\GradedItemResource;
use App\Models\GradedItem;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditGradedItem extends EditRecord
{
    protected static string $resource = GradedItemResource::class;

    /**
     * The columns a hold, a sale or another admin screen may change under an open form, as they were when the form was
     * last filled or saved. A save from a stale page would put an old status or price back, so it is refused.
     */
    #[Locked]
    public string $gradedItemFingerprint = '';

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->color('gray')
                ->outlined(),
        ];
    }

    protected function afterFill(): void
    {
        $this->gradedItemFingerprint = $this->currentFingerprint();
    }

    protected function beforeSave(): void
    {
        // Runs inside the save transaction, before the record is written.
        if ($this->gradedItemFingerprint === $this->currentFingerprint(lockRow: true)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('admin.notifications.graded_changed_elsewhere'))
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    protected function afterSave(): void
    {
        $this->gradedItemFingerprint = $this->currentFingerprint();
    }

    private function currentFingerprint(bool $lockRow = false): string
    {
        $row = GradedItem::query()
            ->whereKey($this->getRecord()->getKey())
            ->when($lockRow, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'updated_at', 'price_satang', 'status']);

        return hash('sha256', json_encode($row?->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    /**
     * A backstop: the form already checks the listing rule field by field, so this is reached only by a state the form
     * could not see. It shows the model's refusal as a message and keeps the page, never a generic error.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (GradedNotListable) {
            Notification::make()
                ->danger()
                ->title(__('admin.notifications.graded_not_listable'))
                ->persistent()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }
}
