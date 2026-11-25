<?php

namespace App\Livewire\Storefront;

use App\Models\CardSet;
use App\Models\Printing;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * D04: every printing of one set in card-number order, with number-range chips, an "in stock only" switch
 * and a search over card names and numbers.
 *
 * The set is ordered in memory from one light query (id, number, language): `number_plain` is a search-index
 * field, not a column, and a set holds a few hundred printings at most. Only the visible page is hydrated into
 * models, so the query count does not grow with the page size.
 */
class SetBrowser extends Component
{
    public const PER_PAGE = 48;

    public const RANGE_SIZE = 50;

    private const MAX_PAGES = 200;

    private const MAX_QUERY_LENGTH = 100;

    #[Locked]
    public int $setId;

    /** The in-set search text: a card name in any script, or part of a number. */
    #[Url(as: 'q')]
    public mixed $query = '';

    /** The start number of the chosen range chip, `extra` for the numbers past `card_count`, empty for the whole set. */
    #[Url]
    public mixed $range = '';

    /** Off by default: collectors browse the whole set. */
    #[Url(as: 'stock')]
    public mixed $inStockOnly = false;

    #[Locked]
    public int $pages = 1;

    public function mount(int $setId): void
    {
        $this->setId = $setId;
        $this->query = is_string($this->query) ? $this->query : '';
        $this->normalise();
    }

    public function updated(): void
    {
        $this->pages = 1;
    }

    public function selectRange(string $range): void
    {
        $this->range = $this->range === $range ? '' : $range;
        $this->pages = 1;
    }

    public function clearFilters(): void
    {
        $this->query = '';
        $this->range = '';
        $this->inStockOnly = false;
        $this->pages = 1;
    }

    public function loadMore(): void
    {
        $this->pages = min($this->pages + 1, self::MAX_PAGES);
    }

    public function render(): View
    {
        $this->normalise();
        $text = $this->searchText();

        $set = CardSet::query()->searchVisible()->with('game')->findOrFail($this->setId);
        CardSet::loadNumberCounts(new EloquentCollection([$set]));

        $all = $this->rows($this->visible($set));
        $chips = $this->chips($set, $all);
        $range = $this->activeRange($chips);

        // A range that is not a chip of this set (a stale link) is dropped.
        $this->range = $range === null ? '' : $this->range;

        $matching = $text === '' && ! $this->inStockOnly
            ? $all
            : $this->rows($this->visible($set)
                ->when($text !== '', fn (Builder $builder) => $builder->where(fn (Builder $inner) => $inner
                    ->whereLike('number', '%'.$text.'%')
                    ->orWhereHas('card', fn (Builder $card) => $card
                        ->whereLike('name_en', '%'.$text.'%')->orWhereLike('name_ja', '%'.$text.'%')->orWhereLike('name_th', '%'.$text.'%'))))
                ->when($this->inStockOnly, fn (Builder $builder) => $builder->whereHas('variants', fn (Builder $variants) => $variants->available())));

        if ($range !== null) {
            $matching = $matching->filter(fn (array $row): bool => $row['plain'] !== null && $row['plain'] >= $range[0] && $row['plain'] <= $range[1])->values();
        }

        $shownRows = $matching->take($this->pages * self::PER_PAGE);
        $next = $matching->slice($shownRows->count(), self::PER_PAGE);

        $printings = $shownRows->isEmpty()
            ? collect()
            : Printing::query()->whereIn('id', $shownRows->pluck('id'))->withCheapestVariant()->get()->keyBy('id');

        return view('livewire.storefront.set-browser', [
            'set' => $set,
            'chips' => $chips,
            'activeRange' => $this->range,
            'printings' => $shownRows->map(fn (array $row): ?Printing => $printings->get($row['id']))->filter()->values(),
            'total' => $matching->count(),
            'next' => $next->isEmpty() ? null : ['from' => $next->first()['number'], 'to' => $next->last()['number']],
            'showLanguage' => $all->pluck('language')->unique()->count() > 1,
            'isFiltered' => $text !== '' || $this->range !== '' || $this->inStockOnly,
        ]);
    }

    /**
     * The set's printings a customer may see: the card is not trashed (the game was checked with the set).
     *
     * @return Builder<Printing>
     */
    private function visible(CardSet $set): Builder
    {
        return Printing::query()->where('set_id', $set->id)->whereHas('card');
    }

    /**
     * Id, number and language of every printing the query matches, in card-number order: by the digits before
     * any `/`, then the number as written, then language. Numbers without digits go last.
     *
     * @param  Builder<Printing>  $query
     * @return Collection<int, array{id: int, number: string, plain: ?int, language: string}>
     */
    private function rows(Builder $query): Collection
    {
        return $query->get(['id', 'number', 'language'])
            ->map(fn (Printing $printing): array => [
                'id' => $printing->id,
                'number' => $printing->number,
                'plain' => Printing::plainNumber($printing->number),
                'language' => $printing->language->value,
            ])
            ->sort(fn (array $a, array $b): int => ($a['plain'] ?? PHP_INT_MAX) <=> ($b['plain'] ?? PHP_INT_MAX)
                ?: strnatcmp($a['number'], $b['number'])
                ?: strcmp($a['language'], $b['language'])
                ?: $a['id'] <=> $b['id'])
            ->values();
    }

    /**
     * The jump chips, built from the numbers that exist: one per bucket of fifty that holds a number, so an odd
     * `card_count` or a huge number never makes a long loop. The last bucket ends at `card_count` (the highest
     * number when it is 0). Number 0 belongs to the first bucket, which then starts at 000. Numbers printed past
     * `card_count` get one more chip, labelled from the data. The key is the bucket index.
     *
     * @param  Collection<int, array{id: int, number: string, plain: ?int, language: string}>  $all
     * @return list<array{key: string, label: string, from: int, to: int}>
     */
    private function chips(CardSet $set, Collection $all): array
    {
        $numbers = $all->pluck('plain')->filter(fn (?int $plain): bool => $plain !== null);

        if ($numbers->isEmpty()) {
            return [];
        }

        $regular = $set->card_count > 0 ? $numbers->filter(fn (int $number): bool => $number <= $set->card_count) : $numbers;
        $end = $set->card_count > 0 ? $set->card_count : $numbers->max();
        $chips = [];

        foreach ($regular->map(fn (int $number): int => intdiv(max($number, 1) - 1, self::RANGE_SIZE))->unique()->sort() as $bucket) {
            $from = $bucket === 0 && $regular->contains(0) ? 0 : $bucket * self::RANGE_SIZE + 1;
            $to = min(($bucket + 1) * self::RANGE_SIZE, $end);
            $chips[] = ['key' => (string) $bucket, 'label' => $this->pad($from).'–'.$this->pad($to), 'from' => $from, 'to' => $to];
        }

        $extras = $set->card_count > 0 ? $numbers->filter(fn (int $number): bool => $number > $set->card_count) : collect();

        if ($extras->isNotEmpty()) {
            $chips[] = ['key' => 'extra', 'label' => $this->pad($extras->min()).'–'.$this->pad($extras->max()).' · '.__('storefront.set.extras'), 'from' => $set->card_count + 1, 'to' => $extras->max()];
        }

        return $chips;
    }

    /**
     * @param  list<array{key: string, label: string, from: int, to: int}>  $chips
     * @return ?array{0: int, 1: int}
     */
    private function activeRange(array $chips): ?array
    {
        $chip = collect($chips)->firstWhere('key', $this->range);

        return $chip === null ? null : [$chip['from'], $chip['to']];
    }

    private function pad(int $number): string
    {
        return str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The search text the query uses. The property stays as typed: `wire:model.live` would overwrite what the
     * visitor is typing (a trailing space) if the response rewrote it.
     */
    private function searchText(): string
    {
        return is_string($this->query) ? trim(mb_substr($this->query, 0, self::MAX_QUERY_LENGTH)) : '';
    }

    /**
     * Whatever the URL or the client put in the properties becomes a value the query can trust.
     */
    private function normalise(): void
    {
        $range = is_int($this->range) ? (string) $this->range : $this->range;
        $this->range = is_string($range) && preg_match('/^(extra|\d{1,6})$/', $range) ? $range : '';
        $this->inStockOnly = in_array($this->inStockOnly, [true, 1, '1', 'true'], true);
        $this->pages = max(1, min($this->pages, self::MAX_PAGES));
    }
}
