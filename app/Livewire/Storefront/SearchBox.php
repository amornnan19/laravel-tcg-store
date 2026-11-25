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

    /**
     * On the results page the field starts with the text that was searched, as the design shows it.
     */
    public function mount(): void
    {
        if (request()->routeIs('search') && is_string(request()->query('q'))) {
            $this->query = $this->cappedQuery(request()->query('q'));
        }
    }

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
            'jumpSets' => $jumpSets = $this->jumpSets($result),
            'enterUrl' => $this->enterUrl($result, $jumpSets),
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
     * Where Enter goes when no row is highlighted and the query names a destination: the card of a card code, or
     * the first set of a set code. Plain text has none, and Enter opens the results page for it.
     *
     * @param  Collection<int, CardSet>  $jumpSets
     */
    private function enterUrl(?AutocompleteResult $result, Collection $jumpSets): ?string
    {
        return match ($result?->jump?->kind) {
            QueryKind::Printing => ($printing = $result->printings->first()) === null
                ? null
                : route('card.show', ['card' => $printing->card, 'p' => $printing->id]),
            QueryKind::Set => ($set = $jumpSets->first()) === null ? null : route('set.show', $set),
            default => null,
        };
    }

    /**
     * The length cap is enforced here as well as by `maxlength`, which a client can bypass.
     */
    private function cappedQuery(?string $value = null): string
    {
        return mb_substr($value ?? $this->query, 0, self::MAX_QUERY_LENGTH);
    }
}
