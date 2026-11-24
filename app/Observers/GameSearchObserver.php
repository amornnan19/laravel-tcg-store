<?php

namespace App\Observers;

use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Printings and sets carry the game slug, and those of an inactive game are not searchable at all.
 */
class GameSearchObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Game $game): void
    {
        Printing::syncSearchIndexFor(Printing::query()->whereHas('set', fn ($sets) => $sets->where('game_id', $game->id)));
        CardSet::syncSearchIndexFor(CardSet::query()->where('game_id', $game->id));
    }
}
