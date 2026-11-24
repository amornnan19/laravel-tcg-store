<?php

use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the database seeder creates five games, five sets and a priced catalogue', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Game::count())->toBe(5)
        ->and(CardSet::count())->toBe(5)
        ->and(Printing::count())->toBeGreaterThan(0)
        ->and(Variant::count())->toBeGreaterThan(Printing::count())
        ->and(Variant::whereNull('sku')->orWhere('sku', '')->count())->toBe(0)
        ->and(Variant::whereNull('price_satang')->count())->toBe(0);
});

test('each game declares the facets it filters on', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Game::where('slug', 'pokemon')->first()->filters)->toBe(['rarity', 'card_type', 'language'])
        ->and(Game::where('slug', 'one-piece')->first()->filters)->toBe(['rarity', 'card_type', 'color', 'language'])
        ->and(Game::where('slug', 'yugioh')->first()->filters)->toContain('attribute');
});

test('seeding twice does not duplicate anything', function () {
    $this->seed(DatabaseSeeder::class);
    $variants = Variant::count();

    $this->seed(DatabaseSeeder::class);

    expect(Variant::count())->toBe($variants)
        ->and(Game::count())->toBe(5);
});

test('cards with the same name but a different type or traits stay separate cards', function () {
    $game = Game::factory()->create();
    $row = fn (string $number, string $type, array $traits) => [
        'name_en' => 'Sabo', 'card_type' => $type, 'traits' => $traits, 'number' => $number,
        'rarity_code' => 'r', 'language' => 'en', 'has_foil' => false,
    ];

    (new CatalogueSeeder)->seedSet($game, [
        'set' => ['code' => 'OP-05', 'name_en' => 'Test', 'name_th' => null, 'released_on' => null, 'card_count' => 4],
        'cards' => [
            $row('001', 'leader', ['color' => ['Red']]),
            $row('007', 'character', ['color' => ['Red']]),
            // Same name, type and traits: the same card, a second printing.
            $row('008', 'character', ['color' => ['Red']]),
            $row('009', 'character', ['color' => ['Blue']]),
        ],
    ]);

    expect(Card::count())->toBe(3)
        ->and(Printing::count())->toBe(4)
        ->and(Printing::where('number', '007')->first()->card_id)->toBe(Printing::where('number', '008')->first()->card_id);
});

test('trait keys in a different order are the same card', function () {
    $game = Game::factory()->create();
    $row = fn (string $number, array $traits) => [
        'name_en' => 'Zoro', 'card_type' => 'character', 'traits' => $traits, 'number' => $number,
        'rarity_code' => 'r', 'language' => 'en', 'has_foil' => false,
    ];

    (new CatalogueSeeder)->seedSet($game, [
        'set' => ['code' => 'OP-05', 'name_en' => 'Test', 'name_th' => null, 'released_on' => null, 'card_count' => 2],
        'cards' => [
            $row('001', ['color' => ['Green'], 'cost' => '3']),
            $row('002', ['cost' => '3', 'color' => ['Green']]),
        ],
    ]);

    expect(Card::count())->toBe(1);
});
