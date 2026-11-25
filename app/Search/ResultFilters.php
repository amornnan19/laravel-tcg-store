<?php

namespace App\Search;

/**
 * What a results page asks of the catalogue: the text and every filter in docs/search.md's table.
 *
 * Each filter belongs to a group named after its URL parameter. A group maps to one Meilisearch attribute, and
 * `meilisearchFilter()` is the one place that turns the whole request into a filter expression.
 */
final readonly class ResultFilters
{
    /**
     * Group => Meilisearch attribute, for the groups whose options are facets with counts.
     */
    public const FACETS = [
        'game' => 'game',
        'set' => 'set_code',
        'rarity' => 'rarity',
        'type' => 'card_type',
        'color' => 'traits.color',
        'attribute' => 'traits.attribute',
        'language' => 'language',
        'condition' => 'conditions',
    ];

    /**
     * Every group, in the order a filter is most likely to be the one that emptied the results.
     */
    public const GROUPS = ['condition', 'price', 'foil', 'photo', 'rarity', 'type', 'color', 'attribute', 'language', 'set', 'game', 'stock'];

    /**
     * @param  list<string>  $games  Game slugs.
     * @param  list<string>  $sets  Stored set codes.
     * @param  list<string>  $rarities
     * @param  list<string>  $cardTypes
     * @param  list<string>  $colors
     * @param  list<string>  $attributes
     * @param  list<string>  $languages
     * @param  list<string>  $conditions
     */
    public function __construct(
        public string $text = '',
        public array $games = [],
        public array $sets = [],
        public array $rarities = [],
        public array $cardTypes = [],
        public array $colors = [],
        public array $attributes = [],
        public array $languages = [],
        public array $conditions = [],
        public ?int $minSatang = null,
        public ?int $maxSatang = null,
        public bool $inStockOnly = true,
        public bool $foilOnly = false,
        public bool $realPhotoOnly = false,
    ) {}

    /**
     * @return list<string>
     */
    public function activeGroups(): array
    {
        return array_values(array_filter(self::GROUPS, fn (string $group): bool => $this->isActive($group)));
    }

    public function isActive(string $group): bool
    {
        return match ($group) {
            'game' => $this->games !== [],
            'set' => $this->sets !== [],
            'rarity' => $this->rarities !== [],
            'type' => $this->cardTypes !== [],
            'color' => $this->colors !== [],
            'attribute' => $this->attributes !== [],
            'language' => $this->languages !== [],
            'condition' => $this->conditions !== [],
            'price' => $this->minSatang !== null || $this->maxSatang !== null,
            'stock' => $this->inStockOnly,
            'foil' => $this->foilOnly,
            'photo' => $this->realPhotoOnly,
            default => false,
        };
    }

    /**
     * The same request with one group switched off (the stock group goes back to "include sold out").
     */
    public function without(string $group): self
    {
        $cleared = match ($group) {
            'game' => ['games' => []],
            'set' => ['sets' => []],
            'rarity' => ['rarities' => []],
            'type' => ['cardTypes' => []],
            'color' => ['colors' => []],
            'attribute' => ['attributes' => []],
            'language' => ['languages' => []],
            'condition' => ['conditions' => []],
            'price' => ['minSatang' => null, 'maxSatang' => null],
            'stock' => ['inStockOnly' => false],
            'foil' => ['foilOnly' => false],
            'photo' => ['realPhotoOnly' => false],
            default => [],
        };

        return new self(...array_merge(get_object_vars($this), $cleared));
    }

    /**
     * The Meilisearch filter expression, every group ANDed and every option within a group ORed
     * (`conditions IN [...]` matches when any of an array's values does).
     *
     * @param  ?string  $except  A group left out, to count the options of that group against all the other filters.
     */
    public function meilisearchFilter(?string $except = null): string
    {
        $filters = $except === null ? $this : $this->without($except);
        $expressions = [];

        foreach ([
            'game' => $filters->games,
            'set' => $filters->sets,
            'rarity' => $filters->rarities,
            'type' => $filters->cardTypes,
            'color' => $filters->colors,
            'attribute' => $filters->attributes,
            'language' => $filters->languages,
            'condition' => $filters->conditions,
        ] as $group => $values) {
            if ($values !== []) {
                $expressions[] = self::FACETS[$group].' IN ['.implode(', ', array_map(self::quote(...), $values)).']';
            }
        }

        if ($filters->minSatang !== null) {
            $expressions[] = 'from_satang >= '.$filters->minSatang;
        }

        if ($filters->maxSatang !== null) {
            $expressions[] = 'from_satang <= '.$filters->maxSatang;
        }

        foreach (['in_stock' => $filters->inStockOnly, 'has_foil' => $filters->foilOnly, 'has_real_photo' => $filters->realPhotoOnly] as $attribute => $on) {
            if ($on) {
                $expressions[] = $attribute.' = true';
            }
        }

        return implode(' AND ', $expressions);
    }

    private static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
