<?php

namespace App\Observers;

use App\Models\Card;
use App\Models\Printing;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A card's names and traits are indexed on each of its printings; a trashed card drops them from the index.
 */
class CardSearchObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Card $card): void
    {
        $this->reindex($card);
    }

    public function deleted(Card $card): void
    {
        $this->reindex($card);
    }

    public function restored(Card $card): void
    {
        $this->reindex($card);
    }

    private function reindex(Card $card): void
    {
        Printing::syncSearchIndexFor(Printing::query()->where('card_id', $card->id));
    }
}
