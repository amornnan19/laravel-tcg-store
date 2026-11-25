<?php

namespace App\Livewire\Storefront;

use App\Enums\CardCondition;
use App\Enums\Language;
use App\Models\CardSet;
use App\Models\Game;
use App\Search\CatalogueResults;
use App\Search\ResultFilters;
use App\Search\ResultPage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * M03/D02: the filtered results of `/search` and `/g/{game}`, with the filter panel beside or over them.
 *
 * Every filter is a public property in the URL, so a filtered result set is a link. The properties are `mixed`
 * on purpose: a hand-edited URL (`?rarity=x`, `?min=abc`) must reach `normalise()` instead of a type error.
 */
class ResultGrid extends Component
{
    public const PER_PAGE = 24;

    private const MAX_PAGES = 40;

    private const MAX_VALUES = 20;

    private const MAX_VALUE_LENGTH = 64;

    private const MAX_QUERY_LENGTH = 100;

    private const MAX_BAHT = 10_000_000;

    /**
     * The URL parameters a visitor can change, which is what the phone sheet's "cancel" puts back.
     */
    public const FILTER_KEYS = ['game', 'set', 'rarity', 'type', 'color', 'attribute', 'language', 'condition', 'min', 'max', 'soldout', 'foil', 'photo'];

    private const RARITY_ORDER = ['c', 'u', 'r', 'rr', 'sar', 'sec'];

    #[Locked]
    public ?string $lockedGame = null;

    #[Url(as: 'q')]
    public mixed $query = '';

    #[Url]
    public mixed $game = [];

    #[Url]
    public mixed $set = [];

    #[Url]
    public mixed $rarity = [];

    #[Url]
    public mixed $type = [];

    #[Url]
    public mixed $color = [];

    #[Url]
    public mixed $attribute = [];

    #[Url]
    public mixed $language = [];

    #[Url]
    public mixed $condition = [];

    /** Lowest price in baht. */
    #[Url]
    public mixed $min = null;

    /** Highest price in baht. */
    #[Url]
    public mixed $max = null;

    /** "In stock only" is the default, so the parameter that exists is the one that turns it off. */
    #[Url]
    public mixed $soldout = false;

    #[Url]
    public mixed $foil = false;

    #[Url]
    public mixed $photo = false;

    /** Empty is the page's own default order; see `effectiveSort()`. */
    #[Url]
    public mixed $sort = '';

    #[Url]
    public mixed $view = 'grid';

    #[Locked]
    public int $pages = 1;

    /**
     * The groups in the order the visitor switched them on; the last one is the first suspect of an empty result.
     *
     * @var list<string>
     */
    #[Locked]
    public array $touched = [];

    public function mount(?string $gameSlug = null): void
    {
        $this->lockedGame = $gameSlug;
        $this->normalise();
    }

    public function updated(string $name): void
    {
        if ($name !== 'view') {
            $this->pages = 1;
        }

        $this->touch(match (true) {
            in_array($name, ['min', 'max'], true) => 'price',
            $name === 'soldout' => 'stock',
            $name === 'photo' => 'photo',
            $name === 'foil' => 'foil',
            default => $name,
        });
    }

    /**
     * Add or remove one option of a multi-value filter.
     */
    public function toggle(string $group, string $value): void
    {
        if (! in_array($group, ['game', 'set', 'rarity', 'type', 'color', 'attribute', 'language', 'condition'], true)) {
            return;
        }

        $values = $this->values($this->{$group});

        $this->{$group} = in_array($value, $values, true)
            ? array_values(array_diff($values, [$value]))
            : [...$values, $value];

        $this->pages = 1;
        $this->touch($group);
    }

    /**
     * Switch "in stock only", "foil only" or "real photo only".
     */
    public function toggleFlag(string $group): void
    {
        match ($group) {
            'stock' => $this->soldout = ! $this->flag($this->soldout),
            'foil' => $this->foil = ! $this->flag($this->foil),
            'photo' => $this->photo = ! $this->flag($this->photo),
            default => null,
        };

        $this->pages = 1;
        $this->touch($group);
    }

    /**
     * @param  string  $bound  `min` or `max`, in baht; blank removes it.
     */
    public function setPrice(string $bound, mixed $value): void
    {
        if (in_array($bound, ['min', 'max'], true)) {
            $this->{$bound} = $value;
        }

        $this->pages = 1;
        $this->touch('price');
    }

    public function clearGroup(string $group): void
    {
        match ($group) {
            'game', 'set', 'rarity', 'type', 'color', 'attribute', 'language', 'condition' => $this->{$group} = [],
            'price' => [$this->min, $this->max] = [null, null],
            'stock' => $this->soldout = true,
            'foil' => $this->foil = false,
            'photo' => $this->photo = false,
            default => null,
        };

        $this->pages = 1;
        $this->touch($group);
    }

    /**
     * Every filter back to its default. The text and the page's own game stay.
     */
    public function clearAll(): void
    {
        $this->restore([]);
    }

    /**
     * Put the filters back to a state read earlier, or to the defaults for anything it leaves out.
     *
     * @param  array<string, mixed>  $state
     */
    public function restore(array $state): void
    {
        $defaults = ['game' => [], 'set' => [], 'rarity' => [], 'type' => [], 'color' => [], 'attribute' => [], 'language' => [], 'condition' => [], 'min' => null, 'max' => null, 'soldout' => false, 'foil' => false, 'photo' => false];

        foreach (self::FILTER_KEYS as $key) {
            $this->{$key} = $state[$key] ?? $defaults[$key];
        }

        $this->pages = 1;
        $this->touched = [];
    }

    public function loadMore(): void
    {
        $this->pages = min($this->pages + 1, self::MAX_PAGES);
    }

    public function render(CatalogueResults $results): View
    {
        $this->normalise();

        $filters = $this->filters();
        $games = Game::query()->where('is_active', true)->orderBy('sort')->get();
        $declared = $this->declared($games);
        $facetGroups = array_values(array_filter(
            ['game', 'set', 'rarity', 'type', 'color', 'attribute', 'language', 'condition'],
            fn (string $group): bool => match ($group) {
                'game' => $this->lockedGame === null,
                'set', 'condition' => true,
                default => $declared[$group],
            },
        ));

        $page = $results->search($filters, $this->effectiveSort(), $this->pages * self::PER_PAGE, $facetGroups);

        $chips = $this->chips($filters, $games);

        return view('livewire.storefront.result-grid', [
            'page' => $page,
            'pageGame' => $this->lockedGame === null ? null : $games->firstWhere('slug', $this->lockedGame),
            'chips' => $chips,
            'suggestion' => $page->total === 0 ? $this->suggestion($results, $filters, $chips, $page->limited) : null,
            'panel' => $this->panel($page, $filters, $games, $declared, $chips),
            'text' => $filters->text,
            'shown' => $page->printings->count(),
            'sorts' => array_values(array_filter(CatalogueResults::SORTS, fn (string $sort): bool => $sort !== 'relevance' || $filters->text !== '')),
            'activeSort' => $this->effectiveSort(),
        ]);
    }

    private function filters(): ResultFilters
    {
        return new ResultFilters(
            text: $this->query,
            games: $this->lockedGame === null ? $this->game : [$this->lockedGame],
            sets: $this->set,
            rarities: $this->rarity,
            cardTypes: $this->type,
            colors: $this->color,
            attributes: $this->attribute,
            languages: $this->language,
            conditions: $this->condition,
            minSatang: $this->min === null ? null : $this->min * 100,
            maxSatang: $this->max === null ? null : $this->max * 100,
            inStockOnly: ! $this->soldout,
            foilOnly: $this->foil,
            realPhotoOnly: $this->photo,
        );
    }

    /**
     * Whatever the URL or the client put in the properties becomes a value the query can trust.
     */
    private function normalise(): void
    {
        $this->query = trim(mb_substr(is_string($this->query) ? $this->query : '', 0, self::MAX_QUERY_LENGTH));

        foreach (['game', 'set', 'rarity', 'type', 'color', 'attribute'] as $group) {
            $this->{$group} = $this->values($this->{$group});
        }

        $this->language = array_values(array_intersect($this->values($this->language), array_column(Language::cases(), 'value')));
        $this->condition = array_values(array_intersect($this->values($this->condition), array_column(CardCondition::cases(), 'value')));

        if ($this->lockedGame !== null) {
            $this->game = [];
        }

        $this->min = $this->baht($this->min);
        $this->max = $this->baht($this->max);

        if ($this->min !== null && $this->max !== null && $this->min > $this->max) {
            [$this->min, $this->max] = [$this->max, $this->min];
        }

        $this->soldout = $this->flag($this->soldout);
        $this->foil = $this->flag($this->foil);
        $this->photo = $this->flag($this->photo);
        $this->sort = in_array($this->sort, CatalogueResults::SORTS, true) ? $this->sort : '';
        $this->view = $this->view === 'list' ? 'list' : 'grid';
        $this->pages = max(1, min($this->pages, self::MAX_PAGES));
    }

    /**
     * @return list<string>
     */
    private function values(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => is_string($item) && $item !== '' && mb_strlen($item) <= self::MAX_VALUE_LENGTH)
            ->unique()
            ->take(self::MAX_VALUES)
            ->values()
            ->all();
    }

    private function baht(mixed $value): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,8}$/', trim((string) $value))) {
            return null;
        }

        return min((int) $value, self::MAX_BAHT);
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /**
     * Remember that the visitor just changed a group: the last group switched on is the first suspect of an empty
     * result. A page opened from a URL has no history, so its suggestion starts from `ResultFilters::GROUPS`.
     */
    private function touch(string $group): void
    {
        $this->normalise();

        $this->touched = array_values(array_diff($this->touched, [$group]));

        if ($this->filters()->isActive($group) && $group !== 'stock') {
            $this->touched[] = $group;
        }
    }

    /**
     * The order in force: the visitor's choice, else best match for a text and card number for a plain listing.
     * "Best match" means nothing without a text, so it is only offered, and only honoured, with one.
     */
    private function effectiveSort(): string
    {
        return match (true) {
            $this->sort === 'relevance' && $this->query === '' => 'number',
            $this->sort !== '' => $this->sort,
            default => $this->query === '' ? 'number' : 'relevance',
        };
    }

    /**
     * Which of `type`, `color` and `attribute` the panel offers, and whether `rarity` and `language` are: the
     * filters every selected game declares in `games.filters`. With no game selected only the generic ones show.
     *
     * @param  Collection<int, Game>  $games
     * @return array{rarity: bool, type: bool, color: bool, attribute: bool, language: bool}
     */
    private function declared(Collection $games): array
    {
        $selected = $games->whereIn('slug', $this->lockedGame === null ? $this->game : [$this->lockedGame]);

        return collect(['rarity', 'type', 'color', 'attribute', 'language'])->mapWithKeys(fn (string $key): array => [
            $key => $selected->isEmpty()
                ? in_array($key, ['rarity', 'language'], true)
                : $selected->every(fn (Game $game): bool => in_array($key === 'type' ? 'card_type' : $key, (array) $game->filters, true)),
        ])->all();
    }

    /**
     * The removable chips for the filters in force: a group per option, one for the price and one per switch.
     *
     * @param  Collection<int, Game>  $games
     * @return list<array{group: string, value: ?string, label: string}>
     */
    private function chips(ResultFilters $filters, Collection $games): array
    {
        $chips = [];

        foreach ([
            'game' => $this->lockedGame === null ? $filters->games : [],
            'set' => $filters->sets,
            'rarity' => $filters->rarities,
            'type' => $filters->cardTypes,
            'color' => $filters->colors,
            'attribute' => $filters->attributes,
            'language' => $filters->languages,
            'condition' => $filters->conditions,
        ] as $group => $values) {
            foreach ($values as $value) {
                $chips[] = ['group' => $group, 'value' => $value, 'label' => $this->optionLabel($group, $value, $games)];
            }
        }

        if ($filters->isActive('price')) {
            $chips[] = ['group' => 'price', 'value' => null, 'label' => $this->priceLabel()];
        }

        // In stock only is the quiet default: it has a chip only once the visitor has switched it off.
        if (! $filters->inStockOnly) {
            $chips[] = ['group' => 'stock', 'value' => null, 'label' => __('storefront.results.include_sold_out')];
        }

        foreach (['foil' => 'foil', 'photo' => 'photo'] as $group => $key) {
            if ($filters->isActive($group)) {
                $chips[] = ['group' => $group, 'value' => null, 'label' => __('storefront.results.toggle.'.$key)];
            }
        }

        return $chips;
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    private function optionLabel(string $group, string $value, Collection $games): string
    {
        return match ($group) {
            'game' => $games->firstWhere('slug', $value)?->name_en ?? $value,
            'set' => strtoupper($value),
            'rarity' => strtoupper($value),
            'language' => Language::tryFrom($value)?->shortCode() ?? strtoupper($value),
            'condition' => strtoupper($value),
            default => Str::headline($value),
        };
    }

    private function priceLabel(): string
    {
        $format = fn (int $baht): string => '฿ '.Number::format($baht);

        return match (true) {
            $this->min !== null && $this->max !== null => $format($this->min).' – '.Number::format($this->max),
            $this->min !== null => __('storefront.results.price_from', ['price' => $format($this->min)]),
            default => __('storefront.results.price_to', ['price' => $format($this->max)]),
        };
    }

    /**
     * What to take off to get results back: the filter switched on last whose removal leaves at least one printing.
     *
     * @param  list<array{group: string, value: ?string, label: string}>  $chips
     * @return ?array{group: string, name: string, label: string, count: int}
     */
    private function suggestion(CatalogueResults $results, ResultFilters $filters, array $chips, bool $limited): ?array
    {
        $candidates = [
            ...array_reverse($this->touched),
            ...array_diff(ResultFilters::GROUPS, $this->touched),
        ];

        foreach ($candidates as $group) {
            if (! $filters->isActive($group) || ($group === 'game' && $this->lockedGame !== null)) {
                continue;
            }

            $count = $results->count($filters->without($group), $limited);

            if ($count > 0) {
                $label = $group === 'stock' ? __('storefront.results.toggle.in_stock') : collect($chips)->where('group', $group)->pluck('label')->implode(', ');

                return ['group' => $group, 'name' => __('storefront.results.group.'.$group), 'label' => $label, 'count' => $count];
            }
        }

        return null;
    }

    /**
     * Everything the filter panel draws, so the panel itself holds no query and no state.
     *
     * @param  Collection<int, Game>  $games
     * @param  array{rarity: bool, type: bool, color: bool, attribute: bool, language: bool}  $declared
     * @param  list<array{group: string, value: ?string, label: string}>  $chips
     * @return array<string, mixed>
     */
    private function panel(ResultPage $page, ResultFilters $filters, Collection $games, array $declared, array $chips): array
    {
        $counted = ! $page->limited;
        $sections = [];

        if ($this->lockedGame === null) {
            $sections[] = $this->section('game', $games->pluck('slug')->all(), $page, $counted, $this->game, fn (string $slug): string => $this->optionLabel('game', $slug, $games));
        }

        $sections[] = $this->setSection($page, $filters, $games, $counted);

        if ($counted && $declared['rarity']) {
            $sections[] = $this->section('rarity', self::RARITY_ORDER, $page, true, $this->rarity, fn (string $code): string => strtoupper($code));
        }

        foreach (['type', 'color', 'attribute'] as $group) {
            if ($counted && $declared[$group]) {
                $sections[] = $this->section($group, [], $page, true, $this->{$group}, fn (string $value): string => Str::headline($value));
            }
        }

        if ($declared['language']) {
            $sections[] = $this->section('language', array_column(Language::cases(), 'value'), $page, $counted, $this->language, fn (string $value): string => Language::from($value)->shortCode());
        }

        if ($counted) {
            $sections[] = $this->section('condition', array_column(CardCondition::cases(), 'value'), $page, true, $this->condition, fn (string $value): string => strtoupper($value));
        }

        return [
            'sections' => array_values(array_filter($sections)),
            'price' => $counted ? ['min' => $this->min, 'max' => $this->max] : null,
            'flags' => [
                'stock' => ! $this->soldout,
                ...($counted ? ['foil' => $this->foil, 'photo' => $this->photo] : []),
            ],
            'count' => $page->total,
            'active' => count(array_unique(array_column($chips, 'group'))),
            'limited' => $page->limited,
            'keys' => self::FILTER_KEYS,
        ];
    }

    /**
     * One filter group as options with counts. `$order` fixes the order of known values; anything else the
     * engine counted follows alphabetically. A selected option stays listed at zero so it can be switched off.
     *
     * @param  list<string>  $order
     * @param  list<string>  $selected
     * @return ?array{key: string, label: string, style: string, options: list<array{value: string, label: string, count: ?int, active: bool}>}
     */
    private function section(string $group, array $order, ResultPage $page, bool $counted, array $selected, callable $label): ?array
    {
        $facet = $page->facets[$group] ?? [];
        $values = collect($order)
            ->filter(fn (string $value): bool => ! $counted || isset($facet[$value]) || in_array($value, $selected, true))
            ->merge(collect($facet)->keys()->diff($order)->sort()->values())
            ->merge(collect($selected)->diff($order)->diff(array_keys($facet)))
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return [
            'key' => $group,
            'label' => __('storefront.results.group.'.$group),
            'style' => 'chips',
            'options' => $values->map(fn (string $value): array => [
                'value' => $value,
                'label' => $label($value),
                'count' => $counted ? (int) ($facet[$value] ?? 0) : null,
                'active' => in_array($value, $selected, true),
            ])->all(),
        ];
    }

    /**
     * The set list: a searchable column of "CODE — name" rows, because 42 sets do not fit as chips.
     *
     * @param  Collection<int, Game>  $games
     * @return ?array<string, mixed>
     */
    private function setSection(ResultPage $page, ResultFilters $filters, Collection $games, bool $counted): ?array
    {
        $facet = $page->facets['set'] ?? [];

        if ($counted && $facet === [] && $this->set === []) {
            return null;
        }

        $sets = CardSet::query()
            ->searchVisible()
            ->when($counted, fn ($query) => $query->whereIn('code', [...array_keys($facet), ...$this->set]))
            ->when(! $counted && $filters->games !== [], fn ($query) => $query->whereHas('game', fn ($game) => $game->whereIn('slug', $filters->games)))
            ->orderBy('sort')
            ->orderBy('code')
            ->get(['code', 'name_en'])
            ->unique('code');

        if ($sets->isEmpty()) {
            return null;
        }

        return [
            'key' => 'set',
            'label' => __('storefront.results.group.set'),
            'style' => 'list',
            'options' => $sets->map(fn (CardSet $set): array => [
                'value' => $set->code,
                'label' => strtoupper($set->code).' — '.$set->name_en,
                'count' => $counted ? (int) ($facet[$set->code] ?? 0) : null,
                'active' => in_array($set->code, $this->set, true),
            ])->values()->all(),
        ];
    }
}
