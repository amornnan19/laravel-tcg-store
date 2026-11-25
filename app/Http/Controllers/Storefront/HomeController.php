<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * The home page (M01/D01): the latest set as the hero, new in-stock singles and the latest sets.
 * Sets, their number counts and the tile grid (printings, card, set, variants) besides the layout's game list.
 */
class HomeController extends Controller
{
    private const NEW_SINGLES = 12;

    private const LATEST_SETS = 7;

    public function __invoke(): View
    {
        $sets = CardSet::query()
            ->searchVisible()
            ->select('sets.*')
            ->addSelect(['game_name' => Game::query()->select('name_en')->whereColumn('games.id', 'sets.game_id')->limit(1)])
            ->orderByRaw('released_on is null')
            ->orderByDesc('released_on')
            ->orderByDesc('id')
            ->limit(self::LATEST_SETS)
            ->get();

        CardSet::loadNumberCounts($sets);

        $newSingles = Printing::query()
            ->searchVisible()
            ->whereHas('variants', fn (Builder $variants) => $variants->available())
            ->withCheapestVariant()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::NEW_SINGLES)
            ->get();

        return view('home', [
            'heroSet' => $sets->first(),
            'latestSets' => $sets->slice(1),
            'newSingles' => $newSingles,
        ]);
    }
}
