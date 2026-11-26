<?php

namespace App\Observers;

use App\Models\CardSet;
use App\Models\Printing;
use App\Models\SealedProduct;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Scout already re-indexes the set itself on save; its printings carry the set code, name and release date, and its sealed products the set code.
 */
class CardSetSearchObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(CardSet $set): void
    {
        Printing::syncSearchIndexFor(Printing::query()->where('set_id', $set->id));
        SealedProduct::syncSearchIndexFor(SealedProduct::query()->where('set_id', $set->id));
    }
}
