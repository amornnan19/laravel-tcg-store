<?php

namespace App\Livewire\Storefront;

use App\Enums\QueryKind;
use App\Models\CardSet;
use App\Search\AutocompleteResult;
use App\Search\CatalogueSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class SearchBox extends Component
{
    public const MAX_QUERY_LENGTH = 100;

    private const RECENT_KEY = 'storefront.recent_searches';

    private const RECENT_LIMIT = 5;

    public string $query = '';

    public function updatedQuery(): void
    {
        $this->query = $this->cappedQuery();
    }

    /**
     * Remember a search the visitor followed a result of. Called when a row is opened.
     */
    public function remember(string $term): void
    {
        $this->skipRender();

        $term = trim(mb_substr($term, 0, self::MAX_QUERY_LENGTH));

        if ($term === '') {
            return;
        }

        $recent = collect($this->recentSearches())
            ->reject(fn (string $existing): bool => $existing === $term)
            ->prepend($term)
            ->take(self::RECENT_LIMIT)
            ->values()
            ->all();

        session()->put(self::RECENT_KEY, $recent);
    }

    public function useRecent(int $index): void
    {
        $this->query = $this->recentSearches()[$index] ?? $this->query;
    }

    public function clear(): void
    {
        $this->query = '';
    }

    /**
     * @return list<string>
     */
    public function recentSearches(): array
    {
        return array_values(array_filter((array) session()->get(self::RECENT_KEY, []), 'is_string'));
    }

    public function render(CatalogueSearch $search): View
    {
        $query = trim($this->cappedQuery());
        $result = $query === '' ? null : $search->autocomplete($query);

        return view('livewire.storefront.search-box', [
            'result' => $result,
            'jumpSets' => $this->jumpSets($result),
            'recent' => $query === '' ? $this->recentSearches() : [],
        ]);
    }

    /**
     * The sets a set-code query jumps to, in catalogue order. A jump only targets what the index shows.
     *
     * @return Collection<int, CardSet>
     */
    private function jumpSets(?AutocompleteResult $result): Collection
    {
        if ($result?->jump?->kind !== QueryKind::Set) {
            return new Collection;
        }

        return CardSet::query()->searchVisible()->whereKey($result->jump->setIds)->orderBy('sort')->get();
    }

    /**
     * The length cap is enforced here as well as by `maxlength`, which a client can bypass.
     */
    private function cappedQuery(): string
    {
        return mb_substr($this->query, 0, self::MAX_QUERY_LENGTH);
    }
}
