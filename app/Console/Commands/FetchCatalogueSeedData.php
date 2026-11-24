<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('catalogue:fetch-seed-data {game?* : Only fetch these game slugs} {--path= : Directory to write the JSON files to} {--retry-delay=4000 : Milliseconds to wait between retries of a failed request}')]
#[Description('Fetch one real set per game from public APIs into database/seeders/data/{game}.json')]
class FetchCatalogueSeedData extends Command
{
    private const USER_AGENT = 'tcg-store-seed/1.0';

    /**
     * The fallback rarity when an API value has no entry in its game's map.
     */
    private const FALLBACK_RARITY = 'r';

    /**
     * Explicit per-game map from the API's rarity onto c, u, r, rr, sar, sec.
     *
     * @var array<string, array<string, string>>
     */
    private const RARITY_MAPS = [
        'pokemon' => [
            'Common' => 'c',
            'Uncommon' => 'u',
            'Rare' => 'r',
            'Rare Holo' => 'r',
            'Double Rare' => 'rr',
            'Ultra Rare' => 'rr',
            'ACE SPEC Rare' => 'rr',
            'Illustration Rare' => 'sar',
            'Special Illustration Rare' => 'sar',
            'Hyper Rare' => 'sec',
        ],
        'magic' => [
            'common' => 'c',
            'uncommon' => 'u',
            'rare' => 'r',
            'mythic' => 'rr',
        ],
        'yugioh' => [
            'Common' => 'c',
            'Rare' => 'r',
            'Super Rare' => 'rr',
            'Ultra Rare' => 'sar',
            'Secret Rare' => 'sec',
            'Quarter Century Secret Rare' => 'sec',
            'Ultimate Rare' => 'sec',
            'Ghost Rare' => 'sec',
        ],
        'one-piece' => [
            'C' => 'c',
            'UC' => 'u',
            'R' => 'r',
            'L' => 'rr',
            'SR' => 'rr',
            'SEC' => 'sec',
        ],
        'digimon' => [
            'C' => 'c',
            'U' => 'u',
            'R' => 'r',
            'SR' => 'rr',
            'SEC' => 'sec',
        ],
    ];

    /**
     * Rarity codes from lowest to highest.
     *
     * @var list<string>
     */
    private const RARITY_ORDER = ['c', 'u', 'r', 'rr', 'sar', 'sec'];

    /**
     * Rarity values that had no map entry, per game.
     *
     * @var array<string, array<string, int>>
     */
    private array $unmappedRarities = [];

    public function handle(): int
    {
        $sources = [
            'pokemon' => fn (): array => $this->fetchPokemon('sv3pt5'),
            'magic' => fn (): array => $this->fetchMagic('blb'),
            'yugioh' => fn (): array => $this->fetchYugioh('Rage of the Abyss'),
            'one-piece' => fn (): array => $this->fetchOnePiece('OP-05'),
            'digimon' => fn (): array => $this->fetchDigimon('BT-16'),
        ];

        $only = $this->argument('game');
        $directory = $this->option('path') ?: database_path('seeders/data');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $failed = false;

        foreach ($sources as $slug => $fetch) {
            if ($only !== [] && ! in_array($slug, $only, true)) {
                continue;
            }

            try {
                $data = $fetch();
            } catch (Throwable $exception) {
                $this->error("{$slug}: {$exception->getMessage()}");
                $failed = true;

                continue;
            }

            file_put_contents(
                "{$directory}/{$slug}.json",
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );

            $this->info(sprintf('%s: %s (%s), %d cards', $slug, $data['set']['name_en'], $data['set']['code'], count($data['cards'])));
        }

        foreach ($this->unmappedRarities as $slug => $values) {
            foreach ($values as $value => $count) {
                $this->warn("{$slug}: rarity [{$value}] is not mapped, {$count} card(s) fell back to [".self::FALLBACK_RARITY.'].');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->acceptJson()
            ->timeout(60)
            ->retry(5, (int) $this->option('retry-delay'), throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPokemon(string $setId): array
    {
        $set = $this->http()->get("https://api.pokemontcg.io/v2/sets/{$setId}")->throw()->json('data');
        $rows = $this->http()
            ->get('https://api.pokemontcg.io/v2/cards', ['q' => "set.id:{$setId}", 'pageSize' => 250])
            ->throw()
            ->json('data');

        return $this->normalisePokemon($set, $rows ?? []);
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function normalisePokemon(array $set, array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            $prices = array_keys($row['tcgplayer']['prices'] ?? []);

            $cards[] = [
                'name_en' => $row['name'],
                'card_type' => Str::lower(Str::ascii($row['supertype'] ?? '')) ?: null,
                'traits' => array_filter([
                    'color' => $row['types'] ?? null,
                    'subtypes' => $row['subtypes'] ?? null,
                ]),
                'number' => (string) $row['number'],
                'rarity_code' => $this->mapRarity('pokemon', $row['rarity'] ?? null),
                'language' => 'en',
                'has_foil' => array_intersect($prices, ['holofoil', 'reverseHolofoil', '1stEditionHolofoil']) !== [],
            ];
        }

        return $this->payload('pokemon', [
            'code' => $set['id'],
            'name_en' => $set['name'],
            'released_on' => str_replace('/', '-', $set['releaseDate'] ?? '') ?: null,
            'card_count' => $set['printedTotal'] ?? count($cards),
        ], $cards);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMagic(string $code): array
    {
        $set = $this->http()->get("https://api.scryfall.com/sets/{$code}")->throw()->json();
        $rows = [];
        $url = 'https://api.scryfall.com/cards/search?'.http_build_query(['q' => "e:{$code}", 'unique' => 'prints', 'order' => 'set']);

        while ($url !== null) {
            $page = $this->http()->get($url)->throw()->json();
            $rows = array_merge($rows, $page['data'] ?? []);
            $url = ($page['has_more'] ?? false) ? $page['next_page'] : null;

            // Scryfall asks for 50-100 ms between requests.
            usleep(150_000);
        }

        return $this->normaliseMagic($set, $rows);
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function normaliseMagic(array $set, array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            $typeLine = $row['type_line'] ?? ($row['card_faces'][0]['type_line'] ?? '');
            $colors = $row['colors'] ?? ($row['card_faces'][0]['colors'] ?? []);

            $cards[] = [
                'name_en' => $row['name'],
                'card_type' => $this->magicCardType($typeLine),
                'traits' => ['color' => $colors === [] ? ['C'] : $colors],
                'number' => (string) $row['collector_number'],
                'rarity_code' => $this->mapRarity('magic', $row['rarity'] ?? null),
                'language' => $row['lang'] ?? 'en',
                'has_foil' => in_array('foil', $row['finishes'] ?? [], true),
            ];
        }

        return $this->payload('magic', [
            'code' => strtolower($set['code']),
            'name_en' => $set['name'],
            'released_on' => $set['released_at'] ?? null,
            'card_count' => $set['card_count'] ?? count($cards),
        ], $this->uniqueByNumber($cards));
    }

    private function magicCardType(string $typeLine): ?string
    {
        $front = trim(explode('//', $typeLine)[0]);
        $types = ['Creature', 'Planeswalker', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land', 'Battle'];

        foreach ($types as $type) {
            if (str_contains($front, $type)) {
                return strtolower($type);
            }
        }

        $first = strtolower(explode(' ', $front)[0]);

        return $first === '' ? null : $first;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchYugioh(string $setName): array
    {
        $rows = $this->http()
            ->get('https://db.ygoprodeck.com/api/v7/cardinfo.php', ['cardset' => $setName])
            ->throw()
            ->json('data');
        $sets = $this->http()->get('https://db.ygoprodeck.com/api/v7/cardsets.php')->throw()->json();
        $set = collect($sets)->firstWhere('set_name', $setName);

        if ($set === null) {
            throw new RuntimeException("YGOPRODeck has no set named [{$setName}].");
        }

        return $this->normaliseYugioh($set, $rows ?? []);
    }

    /**
     * @param  array<string, mixed>  $set  A row of cardsets.php
     * @param  array<int, array<string, mixed>>  $rows  Rows of cardinfo.php for that set
     * @return array<string, mixed>
     */
    public function normaliseYugioh(array $set, array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            foreach ($row['card_sets'] ?? [] as $print) {
                if ($print['set_name'] !== $set['set_name']) {
                    continue;
                }

                // `ROTA-EN062` -> number `062`, language `en`.
                preg_match('/^[A-Za-z0-9]+-([A-Za-z]{2})(.+)$/', $print['set_code'], $parts);
                $rarity = $this->mapRarity('yugioh', $print['set_rarity'] ?? null);

                $cards[] = [
                    'name_en' => $row['name'],
                    'card_type' => $this->yugiohCardType($row['type'] ?? ''),
                    'traits' => array_filter([
                        'attribute' => $row['attribute'] ?? null,
                        'race' => $row['race'] ?? null,
                        'level' => $row['level'] ?? null,
                    ], fn ($value) => $value !== null),
                    'number' => $parts[2] ?? $print['set_code'],
                    'rarity_code' => $rarity,
                    'language' => strtolower($parts[1] ?? 'en'),
                    'has_foil' => ! in_array($rarity, ['c', 'u'], true),
                ];
            }
        }

        // A set code listed under several rarities is the same printing: keep the lowest rarity.
        usort($cards, fn (array $a, array $b): int => array_search($a['rarity_code'], self::RARITY_ORDER, true) <=> array_search($b['rarity_code'], self::RARITY_ORDER, true));

        return $this->payload('yugioh', [
            'code' => $set['set_code'],
            'name_en' => $set['set_name'],
            'released_on' => $set['tcg_date'] ?? null,
            'card_count' => $set['num_of_cards'] ?? count($cards),
        ], $this->uniqueByNumber($cards));
    }

    private function yugiohCardType(string $type): ?string
    {
        return match (true) {
            str_contains($type, 'Monster') => 'monster',
            str_contains($type, 'Spell') => 'spell',
            str_contains($type, 'Trap') => 'trap',
            default => $type === '' ? null : strtolower($type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOnePiece(string $setId): array
    {
        $rows = $this->http()
            ->get('https://optcgapi.com/api/sets/filtered/', ['set_id' => $setId])
            ->throw()
            ->json();

        return $this->normaliseOnePiece($setId, $rows ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function normaliseOnePiece(string $setId, array $rows): array
    {
        $cards = [];
        $prefix = str_replace('-', '', $setId).'-';

        foreach ($rows as $row) {
            // The API lists reprints from other sets and alternate arts (`OP05-119_p1`) in the same set.
            if (! str_starts_with($row['card_set_id'], $prefix) || ($row['card_image_id'] ?? $row['card_set_id']) !== $row['card_set_id']) {
                continue;
            }

            $rarity = $this->mapRarity('one-piece', $row['rarity'] ?? null);

            $cards[] = [
                // The API appends the number to repeated names: `Sabo (007)`.
                'name_en' => preg_replace('/\s*\(\d+\)$/', '', $row['card_name']),
                'card_type' => strtolower($row['card_type'] ?? '') ?: null,
                'traits' => array_filter([
                    'color' => isset($row['card_color']) ? explode(' ', $row['card_color']) : null,
                    'attribute' => $row['attribute'] ?? null,
                    'cost' => $row['card_cost'] ?? null,
                    'power' => $row['card_power'] ?? null,
                    'sub_types' => $row['sub_types'] ?? null,
                ], fn ($value) => $value !== null),
                // `OP05-105` -> `105`
                'number' => Arr::last(explode('-', $row['card_set_id'])),
                'rarity_code' => $rarity,
                'language' => 'en',
                'has_foil' => ! in_array($rarity, ['c', 'u'], true),
            ];
        }

        $cards = $this->uniqueByNumber($cards);

        return $this->payload('one-piece', [
            'code' => $setId,
            'name_en' => $rows[0]['set_name'] ?? $setId,
            'released_on' => null,
            'card_count' => count($cards),
        ], $cards);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDigimon(string $pack): array
    {
        $rows = $this->http()
            ->get('https://digimoncard.io/api-public/search.php', ['pack' => $pack, 'sort' => 'name'])
            ->throw()
            ->json();

        return $this->normaliseDigimon($pack, $rows ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function normaliseDigimon(string $pack, array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            $rarity = $this->mapRarity('digimon', $row['rarity'] ?? null);

            $cards[] = [
                'name_en' => $row['name'],
                'card_type' => strtolower($row['type'] ?? '') ?: null,
                'traits' => array_filter([
                    'color' => array_values(array_filter([$row['color'] ?? null, $row['color2'] ?? null])),
                    'attribute' => $row['attribute'] ?? null,
                    'level' => $row['level'] ?? null,
                    'play_cost' => $row['play_cost'] ?? null,
                    'digi_type' => $row['digi_type'] ?? null,
                ], fn ($value) => $value !== null && $value !== []),
                // `BT16-047` -> `047`
                'number' => Arr::last(explode('-', $row['id'])),
                'rarity_code' => $rarity,
                'language' => 'en',
                'has_foil' => ! in_array($rarity, ['c', 'u'], true),
            ];
        }

        $cards = $this->uniqueByNumber($cards);

        return $this->payload('digimon', [
            'code' => $pack,
            'name_en' => $this->digimonSetName($pack, $rows),
            'released_on' => null,
            'card_count' => count($cards),
        ], $cards);
    }

    /**
     * The API labels each card `BT-16: Booster Beginning Observer`; keep the part after the colon.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function digimonSetName(string $pack, array $rows): string
    {
        $label = Arr::first(Arr::wrap($rows[0]['set_name'] ?? null));

        return $label === null ? $pack : trim(Str::after($label, ':'));
    }

    private function mapRarity(string $game, ?string $apiRarity): string
    {
        $mapped = self::RARITY_MAPS[$game][$apiRarity ?? ''] ?? null;

        if ($mapped !== null) {
            return $mapped;
        }

        $key = $apiRarity ?? '(none)';
        $this->unmappedRarities[$game][$key] = ($this->unmappedRarities[$game][$key] ?? 0) + 1;

        return self::FALLBACK_RARITY;
    }

    /**
     * Printings are unique per (set, number, language), so keep the first card for each.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function uniqueByNumber(array $cards): array
    {
        return collect($cards)
            ->unique(fn (array $card): string => $card['number'].'|'.$card['language'])
            ->sortBy('number', SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function payload(string $slug, array $set, array $cards): array
    {
        return [
            'game' => ['slug' => $slug],
            'set' => [
                'code' => $set['code'],
                'name_en' => $set['name_en'],
                'name_th' => null,
                'released_on' => $set['released_on'],
                'card_count' => $set['card_count'],
            ],
            'cards' => $cards,
        ];
    }
}
