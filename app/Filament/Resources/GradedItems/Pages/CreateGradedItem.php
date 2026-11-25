<?php

namespace App\Filament\Resources\GradedItems\Pages;

use App\Exceptions\GradedNotListable;
use App\Filament\Resources\GradedItems\GradedItemResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGradedItem extends CreateRecord
{
    protected static string $resource = GradedItemResource::class;

    /**
     * A backstop: the form already checks the listing rule field by field, so this is reached only by a state the form
     * could not see. It shows the model's refusal as a message and keeps the page, never a generic error.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
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
