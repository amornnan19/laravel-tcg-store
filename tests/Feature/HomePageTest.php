<?php

use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function stockedPrinting(CardSet $set, string $name, int $qty = 3): Printing
{
    $printing = Printing::factory()->for($set, 'set')->for(Card::factory()->for($set->game)->create(['name_en' => $name]))->create();
    Variant::factory()->for($printing)->create(['qty' => $qty, 'price_satang' => 129000]);

    return $printing;
}

test('the home page shows the latest set as the hero, new singles and the other sets', function () {
    $game = Game::factory()->create(['name_en' => 'Pokemon TCG']);
    $old = CardSet::factory()->for($game)->create(['code' => 'OP-05', 'name_en' => 'Older Set', 'released_on' => '2025-01-10']);
    $latest = CardSet::factory()->for($game)->create(['code' => 'SV-12', 'name_en' => 'Newest Set', 'released_on' => '2026-01-23', 'card_count' => 190]);
    stockedPrinting($latest, 'Charizard ex');
    stockedPrinting($latest, 'Sold Out Card', qty: 0);

    $this->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['<h1', 'Newest Set'], false)
        ->assertSee(route('set.show', $latest), false)
        ->assertSee('23 ม.ค. 2569')
        ->assertSee(__('storefront.home.new_singles'))
        ->assertSee('Charizard ex')
        ->assertDontSee('Sold Out Card')
        ->assertSee(__('storefront.home.latest_sets'))
        ->assertSee('OP-05 — Older Set')
        ->assertSee(route('set.show', $old), false)
        ->assertSee(__('storefront.home.set_in_stock', ['n' => 0]));
});

test('new singles are the most recently created in-stock printings, twelve at most', function () {
    $set = CardSet::factory()->for(Game::factory())->create();

    foreach (range(1, 13) as $number) {
        stockedPrinting($set, 'Card '.str_pad((string) $number, 2, '0', STR_PAD_LEFT))->forceFill(['created_at' => now()->subMinutes(20 - $number)])->save();
    }

    $this->get(route('home'))
        ->assertSee('Card 13')
        ->assertSee('Card 02')
        ->assertDontSee('Card 01');
});

test('sections without data are not rendered', function () {
    $set = CardSet::factory()->for(Game::factory())->create(['name_en' => 'Only Set']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Only Set')
        ->assertDontSee(__('storefront.home.new_singles'))
        ->assertDontSee('<h2', false);
});

test('an empty shop shows the empty state and no section', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(__('storefront.home.empty'))
        ->assertDontSee(__('storefront.home.new_singles'))
        ->assertDontSee('<h1', false);
});

test('a set of an inactive game never reaches the home page', function () {
    $hidden = Game::factory()->create(['is_active' => false]);
    $set = CardSet::factory()->for($hidden)->create(['name_en' => 'Hidden Set']);
    stockedPrinting($set, 'Hidden Card');

    $this->get(route('home'))->assertDontSee('Hidden Set')->assertDontSee('Hidden Card');
});

test('the home page runs at most seven queries', function () {
    $game = Game::factory()->create();

    foreach (range(1, 4) as $number) {
        $set = CardSet::factory()->for($game)->create();
        stockedPrinting($set, 'Card '.$number);
        stockedPrinting($set, 'Other '.$number);
    }

    DB::enableQueryLog();
    $this->get(route('home'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(7);
});

test('a trashed card is neither shown nor counted on the home page', function () {
    $set = CardSet::factory()->for(Game::factory())->create(['card_count' => 100]);
    stockedPrinting($set, 'Visible Card');
    $trashed = stockedPrinting($set, 'Trashed Card');
    $trashed->card->delete();

    $this->get(route('home'))
        ->assertSee('Visible Card')
        ->assertDontSee('Trashed Card')
        ->assertSee(__('storefront.home.set_in_stock', ['n' => 1]));
});

test('set counts are distinct card numbers across languages, with secret rares past the set size as extras', function () {
    $set = CardSet::factory()->for(Game::factory())->create(['card_count' => 100]);
    $other = CardSet::factory()->for(Game::factory())->create(['card_count' => 10]);

    foreach ([['001', 'en', 2], ['001', 'ja', 5], ['002', 'en', 0], ['150', 'en', 1], ['150/100', 'ja', 1], ['151', 'en', 0]] as [$number, $language, $qty]) {
        $printing = Printing::factory()->for($set, 'set')->create(['number' => $number, 'language' => $language]);
        Variant::factory()->for($printing)->create(['qty' => $qty]);
    }

    $sets = CardSet::query()->whereKey([$set->id, $other->id])->get();
    DB::enableQueryLog();
    CardSet::loadNumberCounts($sets);

    expect(count(DB::getQueryLog()))->toBe(1)
        ->and($sets->firstWhere('id', $set->id)->in_stock_numbers)->toBe(2)
        ->and($sets->firstWhere('id', $set->id)->extra_numbers)->toBe(2)
        ->and($sets->firstWhere('id', $other->id)->in_stock_numbers)->toBe(0)
        ->and($sets->firstWhere('id', $other->id)->extra_numbers)->toBe(0);
});

test('the home hero shows the card count with extras and the in-stock number count', function () {
    $set = CardSet::factory()->for(Game::factory())->create(['card_count' => 100, 'released_on' => '2026-01-23']);

    foreach ([['001', 'en'], ['001', 'ja'], ['150', 'en']] as [$number, $language]) {
        $printing = Printing::factory()->for($set, 'set')->create(['number' => $number, 'language' => $language]);
        Variant::factory()->for($printing)->create(['qty' => 1]);
    }

    $this->get(route('home'))
        ->assertSee('100 ใบ + 1 ใบพิเศษ · ร้านมีของ 2 ใบ');
});

test('a tile shows the foil tag only when a foil variant can be bought', function () {
    $set = CardSet::factory()->for(Game::factory())->create();
    $printing = stockedPrinting($set, 'Foil Sold Out');
    $printing->forceFill(['has_foil' => true])->save();
    Variant::factory()->for($printing)->foil()->create(['qty' => 0]);

    $this->get(route('home'))->assertSee('Foil Sold Out')->assertDontSee('>'.__('storefront.tile.foil').'<', false);
});
