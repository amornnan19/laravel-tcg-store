<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Livewire\Storefront\SearchBox;
use App\Models\Game;
use App\Search\CatalogueSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/search` and `/g/{game}`: the results page (M03/D02). The filtering itself is the `ResultGrid` component.
 */
class ResultsController extends Controller
{
    /**
     * A card code (`OP05-119`) typed into the search field is a destination, not a search: it goes straight to
     * that printing, like the autocomplete's Enter does.
     */
    public function search(Request $request, CatalogueSearch $search): View|RedirectResponse
    {
        $query = $request->query('q');
        $query = is_string($query) ? mb_substr(trim($query), 0, SearchBox::MAX_QUERY_LENGTH) : '';

        $jump = $query === '' ? null : $search->printingJump($query);

        if ($jump !== null) {
            return redirect()->route('card.show', ['card' => $jump->card, 'p' => $jump->id]);
        }

        return view('results', [
            'title' => $query === '' ? __('storefront.results.title_all') : __('storefront.results.title_query', ['query' => $query]),
            'gameSlug' => null,
        ]);
    }

    public function game(Game $game): View
    {
        abort_unless($game->is_active, 404);

        return view('results', ['title' => $game->name_en, 'gameSlug' => $game->slug]);
    }
}
