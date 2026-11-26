<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\SealedProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * The home page (M01/D01): the latest set as the hero, new in-stock singles, the latest sets and sealed products.
 * Once per session, when `shop.demo_notice` is on, it also opens the "this is a demo" dialog.
 * Sets, their number counts and the tile grid (printings, card, set, variants) besides the layout's game list.
 */
class HomeController extends Controller
{
    private const NEW_SINGLES = 12;

    private const LATEST_SETS = 7;

    private const SEALED_PRODUCTS = 3;

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

        $sealedProducts = SealedProduct::query()
            ->purchasable()
            ->available()
            ->withReservedQty()
            ->addSelect(['game_name' => Game::query()->select('name_en')->whereColumn('games.id', 'sealed_products.game_id')->limit(1)])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SEALED_PRODUCTS)
            ->get();

        $showDemoNotice = config('shop.demo_notice') && ! session()->has('demo_notice_seen');

        if ($showDemoNotice) {
            session()->put('demo_notice_seen', true);
        }

        return view('home', [
            'showDemoNotice' => $showDemoNotice,
            'heroSet' => $sets->first(),
            'latestSets' => $sets->slice(1),
            'newSingles' => $newSingles,
            'sealedProducts' => $sealedProducts,
        ]);
    }
}
