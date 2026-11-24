<?php

namespace App\Observers;

use App\Models\CardSet;
use App\Models\Printing;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Scout already re-indexes the set itself on save; its printings carry the set code, name and release date.
 */
class CardSetSearchObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(CardSet $set): void
    {
        Printing::syncSearchIndexFor(Printing::query()->where('set_id', $set->id));
    }
}
