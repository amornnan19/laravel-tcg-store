<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Fake every source. An override replaces the default stub for the same URL pattern.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeSources(array $overrides = []): void
{
    $stubs = [
        'api.pokemontcg.io/v2/sets/*' => Http::response(['data' => [
            'id' => 'sv3pt5', 'name' => '151', 'printedTotal' => 165, 'releaseDate' => '2023/09/22',
        ]]),
        'api.pokemontcg.io/v2/cards*' => Http::response(['data' => [
            ['name' => 'Bulbasaur', 'number' => '1', 'rarity' => 'Common', 'supertype' => 'Pokémon', 'subtypes' => ['Basic'], 'types' => ['Grass'],
                'tcgplayer' => ['prices' => ['normal' => [], 'reverseHolofoil' => []]]],
            ['name' => 'Mystery', 'number' => '2', 'rarity' => 'Weird Rare', 'supertype' => 'Trainer'],
        ]]),
        'api.scryfall.com/sets/*' => Http::response([
            'code' => 'blb', 'name' => 'Bloomburrow', 'released_at' => '2024-08-02', 'card_count' => 397,
        ]),
        'api.scryfall.com/cards/search*' => Http::response([
            'has_more' => false,
            'data' => [
                ['name' => 'Season of Gathering', 'collector_number' => '200', 'rarity' => 'mythic', 'lang' => 'en',
                    'type_line' => 'Sorcery', 'colors' => ['G'], 'finishes' => ['nonfoil', 'foil']],
                ['name' => 'Wildfire Howl', 'collector_number' => '10', 'rarity' => 'common', 'lang' => 'en',
                    'type_line' => 'Artifact Creature — Golem', 'finishes' => ['nonfoil']],
            ],
        ]),
        'db.ygoprodeck.com/api/v7/cardsets.php*' => Http::response([
            ['set_name' => 'Rage of the Abyss', 'set_code' => 'ROTA', 'num_of_cards' => 101, 'tcg_date' => '2024-10-10'],
        ]),
        'db.ygoprodeck.com/api/v7/cardinfo.php*' => Http::response(['data' => [
            ['name' => 'Abyss-sting Triaina', 'type' => 'Spell Card', 'race' => 'Equip', 'card_sets' => [
                ['set_name' => '2025 Mega-Pack Tin', 'set_code' => 'MP25-EN253', 'set_rarity' => 'Ultra Rare'],
                ['set_name' => 'Rage of the Abyss', 'set_code' => 'ROTA-EN062', 'set_rarity' => 'Secret Rare'],
                ['set_name' => 'Rage of the Abyss', 'set_code' => 'ROTA-EN062', 'set_rarity' => 'Common'],
            ]],
        ]]),
        'optcgapi.com/*' => Http::response([
            ['card_name' => 'Monkey.D.Luffy (119) (Alternate Art)', 'set_name' => 'Awakening of the New Era', 'rarity' => 'SEC',
                'card_set_id' => 'OP05-119', 'card_image_id' => 'OP05-119_p1', 'card_color' => 'Red Black', 'card_type' => 'Character'],
            ['card_name' => 'Monkey.D.Luffy (119)', 'set_name' => 'Awakening of the New Era', 'rarity' => 'SEC',
                'card_set_id' => 'OP05-119', 'card_image_id' => 'OP05-119', 'card_color' => 'Red Black', 'card_type' => 'Character',
                'card_cost' => '5', 'card_power' => '6000', 'attribute' => 'Strike'],
            ['card_name' => 'Roronoa Zoro', 'set_name' => 'Awakening of the New Era', 'rarity' => 'SR',
                'card_set_id' => 'ST01-012', 'card_image_id' => 'ST01-012', 'card_color' => 'Green', 'card_type' => 'Character'],
        ]),
        'digimoncard.io/*' => Http::response([
            ['name' => 'Achillesmon', 'type' => 'Digimon', 'id' => 'BT16-047', 'color' => 'Green', 'color2' => null, 'rarity' => 'U',
                'level' => 6, 'play_cost' => 11, 'attribute' => 'Virus', 'digi_type' => 'Beastkin',
                'set_name' => ['BT-16: Booster Beginning Observer']],
        ]),
    ];

    Http::fake(array_replace($stubs, $overrides));
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->directory = sys_get_temp_dir().'/tcg-seed-'.uniqid();
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function seedFile(string $slug): array
{
    return json_decode(file_get_contents(test()->directory."/{$slug}.json"), true);
}

test('it normalises one payload per source into the seed json shape', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['--path' => $this->directory])->assertSuccessful();

    foreach (['pokemon', 'magic', 'yugioh', 'one-piece', 'digimon'] as $slug) {
        $data = seedFile($slug);

        expect($data)->toHaveKeys(['game', 'set', 'cards'])
            ->and($data['game']['slug'])->toBe($slug)
            ->and($data['set'])->toHaveKeys(['code', 'name_en', 'name_th', 'released_on', 'card_count'])
            ->and($data['set']['name_th'])->toBeNull()
            ->and($data['cards'])->not->toBeEmpty()
            ->and($data['cards'][0])->toHaveKeys(['name_en', 'card_type', 'traits', 'number', 'rarity_code', 'language', 'has_foil']);
    }
});

test('pokemon cards map rarity, foil and set facts', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['pokemon', '--path' => $this->directory])->assertSuccessful();

    $data = seedFile('pokemon');

    expect($data['set'])->toMatchArray(['code' => 'sv3pt5', 'name_en' => '151', 'released_on' => '2023-09-22', 'card_count' => 165])
        ->and($data['cards'][0])->toMatchArray([
            'name_en' => 'Bulbasaur', 'card_type' => 'pokemon', 'number' => '1', 'rarity_code' => 'c', 'language' => 'en', 'has_foil' => true,
        ])
        ->and($data['cards'][0]['traits'])->toBe(['color' => ['Grass'], 'subtypes' => ['Basic']])
        ->and($data['cards'][1]['has_foil'])->toBeFalse();
});

test('an unmapped rarity falls back to r and is reported', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['pokemon', '--path' => $this->directory])
        ->expectsOutputToContain('pokemon: rarity [Weird Rare] is not mapped, 1 card(s) fell back to [r].')
        ->assertSuccessful();

    expect(seedFile('pokemon')['cards'][1]['rarity_code'])->toBe('r');
});

test('magic cards take their type, colour and foil from the card', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['magic', '--path' => $this->directory])->assertSuccessful();

    $cards = collect(seedFile('magic')['cards'])->keyBy('number');

    expect($cards['200'])->toMatchArray(['card_type' => 'sorcery', 'rarity_code' => 'rr', 'has_foil' => true])
        ->and($cards['200']['traits'])->toBe(['color' => ['G']])
        ->and($cards['10'])->toMatchArray(['card_type' => 'creature', 'rarity_code' => 'c', 'has_foil' => false])
        ->and($cards['10']['traits'])->toBe(['color' => ['C']]);
});

test('yugioh keeps only the named set and the lowest rarity per set code', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['yugioh', '--path' => $this->directory])->assertSuccessful();

    $data = seedFile('yugioh');

    expect($data['set'])->toMatchArray(['code' => 'ROTA', 'name_en' => 'Rage of the Abyss', 'card_count' => 101])
        ->and($data['cards'])->toHaveCount(1)
        ->and($data['cards'][0])->toMatchArray(['number' => '062', 'language' => 'en', 'card_type' => 'spell', 'rarity_code' => 'c']);
});

test('one piece drops reprints and alternate arts and cleans repeated names', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['one-piece', '--path' => $this->directory])->assertSuccessful();

    $data = seedFile('one-piece');

    expect($data['cards'])->toHaveCount(1)
        ->and($data['cards'][0])->toMatchArray(['name_en' => 'Monkey.D.Luffy', 'number' => '119', 'rarity_code' => 'sec', 'card_type' => 'character'])
        ->and($data['cards'][0]['traits']['color'])->toBe(['Red', 'Black'])
        ->and($data['set']['card_count'])->toBe(1);
});

test('digimon reads the set name from the card labels', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['digimon', '--path' => $this->directory])->assertSuccessful();

    $data = seedFile('digimon');

    expect($data['set'])->toMatchArray(['code' => 'BT-16', 'name_en' => 'Booster Beginning Observer'])
        ->and($data['cards'][0])->toMatchArray(['number' => '047', 'rarity_code' => 'u', 'card_type' => 'digimon', 'has_foil' => false]);
});

test('every request sends only the generic user agent', function () {
    fakeSources();
    $this->artisan('catalogue:fetch-seed-data', ['--path' => $this->directory])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->header('User-Agent') === ['tcg-store-seed/1.0']);
    Http::assertNotSent(fn (Request $request) => $request->header('User-Agent') !== ['tcg-store-seed/1.0']);
});

test('a failing source fails the command without writing its file', function () {
    fakeSources(['optcgapi.com/*' => Http::response('down', 500)]);

    $this->artisan('catalogue:fetch-seed-data', ['one-piece', '--path' => $this->directory, '--retry-delay' => 0])->assertFailed();

    // one attempt plus four retries
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'optcgapi.com')))->toHaveCount(5);
    expect(file_exists($this->directory.'/one-piece.json'))->toBeFalse();
});
