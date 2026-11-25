<?php

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

uses(RefreshDatabase::class);

function indexedPrinting(array $printing = []): Printing
{
    $game = Game::factory()->create(['slug' => 'one-piece', 'is_active' => true]);
    $set = CardSet::factory()->for($game)->create(['code' => 'OP-05', 'name_en' => 'Awakening of the New Era', 'released_on' => '2024-05-01']);
    $card = Card::factory()->for($game)->create([
        'name_en' => 'Monkey D. Luffy',
        'name_ja' => 'モンキー・D・ルフィ',
        'name_th' => 'ลูฟี่',
        'card_type' => 'character',
        'traits' => ['color' => ['Red']],
    ]);

    return Printing::factory()->for($card)->for($set, 'set')->create($printing + ['number' => '062', 'rarity_code' => 'sec']);
}

test('a printing is indexed with its derived stock fields', function () {
    $printing = indexedPrinting();
    Variant::factory()->for($printing)->create(['condition' => CardCondition::Nm, 'finish' => Finish::Normal, 'price_satang' => 429000, 'qty' => 2]);
    Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'finish' => Finish::Normal, 'price_satang' => 359000, 'qty' => 3]);
    Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'finish' => Finish::Foil, 'price_satang' => 100, 'qty' => 0]);
    Variant::factory()->for($printing)->create(['condition' => CardCondition::Mp, 'finish' => Finish::Normal, 'price_satang' => null, 'qty' => 4, 'has_real_photo' => true]);

    $document = $printing->fresh()->toSearchableArray();

    expect($document)->toMatchArray([
        'id' => $printing->id,
        'card_id' => $printing->card_id,
        'name_en' => 'Monkey D. Luffy',
        'name_ja' => 'モンキー・D・ルフィ',
        'name_th' => 'ลูฟี่',
        'set_code' => 'OP-05',
        'set_name' => 'Awakening of the New Era',
        'number' => '062',
        'number_plain' => 62,
        'game' => 'one-piece',
        'rarity' => 'sec',
        'language' => 'en',
        'card_type' => 'character',
        'traits' => ['color' => ['Red']],
        'in_stock' => true,
        'available_qty' => 9,
        'from_satang' => 359000,
        'from_satang_any' => 100,
        'has_real_photo' => true,
        'released_on' => '2024-05-01',
    ])->and($document['conditions'])->toEqualCanonicalizing(['nm', 'lp', 'mp']);
});

test('a printing with no available variant is out of stock with no price', function () {
    $printing = indexedPrinting();
    Variant::factory()->for($printing)->create(['price_satang' => 5000, 'qty' => 0]);

    expect($printing->fresh()->toSearchableArray())->toMatchArray([
        'in_stock' => false,
        'available_qty' => 0,
        'from_satang' => null,
        'from_satang_any' => 5000,
        'conditions' => [],
    ]);
});

test('the printing release date wins over the set release date', function () {
    $printing = indexedPrinting(['released_on' => '2025-01-15']);

    expect($printing->fresh()->toSearchableArray()['released_on'])->toBe('2025-01-15');
});

test('number_plain is the integer before any slash and null without digits', function (string $number, ?int $expected) {
    expect(Printing::plainNumber($number))->toBe($expected);
})->with([
    'padded' => ['062', 62],
    'fraction' => ['215/197', 215],
    'plain' => ['190', 190],
    'region prefix' => ['EN062', 62],
    'no digits' => ['SP', null],
    'empty' => ['', null],
]);

test('the available scope counts only variants with stock', function () {
    $printing = indexedPrinting();
    $inStock = Variant::factory()->for($printing)->create(['qty' => 1]);
    Variant::factory()->for($printing)->foil()->create(['qty' => 0]);

    expect(Variant::query()->available()->pluck('id')->all())->toBe([$inStock->id])
        ->and($inStock->isAvailable())->toBeTrue()
        ->and(Variant::factory()->make(['qty' => 0])->isAvailable())->toBeFalse();
});

test('a trashed printing, a trashed card and an inactive game are not searchable', function () {
    $printing = indexedPrinting();

    expect($printing->fresh()->shouldBeSearchable())->toBeTrue();

    $printing->card->delete();
    expect($printing->fresh()->shouldBeSearchable())->toBeFalse();
    $printing->card()->withTrashed()->first()->restore();

    $printing->set->game->update(['is_active' => false]);
    expect($printing->fresh()->shouldBeSearchable())->toBeFalse();
    $printing->set->game->update(['is_active' => true]);

    $printing->delete();
    expect($printing->fresh()->shouldBeSearchable())->toBeFalse();
});

test('the settings name the index and the engine settings cover both models', function () {
    expect(config('scout.meilisearch.index-settings')[Printing::class]['rankingRules'])->toContain('in_stock:desc', 'from_satang:asc')
        ->and(config('scout.meilisearch.index-settings')[CardSet::class]['searchableAttributes'])->toBe(['code', 'name_en', 'name_th'])
        ->and((new Printing)->searchableAs())->toBe('printings')
        ->and((new CardSet)->searchableAs())->toBe('sets');
});

test('a set of an inactive game is not searchable', function () {
    $set = indexedPrinting()->set;

    expect($set->fresh()->shouldBeSearchable())->toBeTrue();

    $set->game->update(['is_active' => false]);

    expect($set->fresh()->shouldBeSearchable())->toBeFalse();
});

test('a set is indexed with its code, names, game and card count', function () {
    $set = indexedPrinting()->set;

    expect($set->toSearchableArray())->toBe([
        'id' => $set->id,
        'code' => 'OP-05',
        'name_en' => 'Awakening of the New Era',
        'name_th' => $set->name_th,
        'game' => 'one-piece',
        'card_count' => $set->card_count,
    ]);
});

describe('freshness', function () {
    beforeEach(function () {
        config()->set('scout.queue', true);
    });

    /**
     * @return list<int>
     */
    function queuedPrintingIds(string $job = MakeSearchable::class): array
    {
        return Queue::pushed($job)
            ->flatMap(fn (object $queued) => $queued->models->pluck('id'))
            ->unique()->values()->all();
    }

    test('changing a variant quantity re-indexes its printing', function () {
        $printing = indexedPrinting();
        $variant = Variant::factory()->for($printing)->create(['qty' => 1]);

        Queue::fake();
        $variant->update(['qty' => 0]);

        expect(queuedPrintingIds())->toBe([$printing->id]);
    });

    test('deleting a variant re-indexes its printing', function () {
        $printing = indexedPrinting();
        $variant = Variant::factory()->for($printing)->create(['qty' => 1]);

        Queue::fake();
        $variant->delete();

        expect(queuedPrintingIds())->toBe([$printing->id]);
    });

    test('moving a variant to another printing re-indexes both', function () {
        $printing = indexedPrinting();
        $other = Printing::factory()->for($printing->set, 'set')->for($printing->card)->create();
        $variant = Variant::factory()->for($printing)->create();

        Queue::fake();
        DB::transaction(fn () => $variant->update(['printing_id' => $other->id]));

        expect(queuedPrintingIds())->toEqualCanonicalizing([$printing->id, $other->id]);
    });

    test('editing a card re-indexes its printings and trashing it removes them', function () {
        $printing = indexedPrinting();

        Queue::fake();
        $printing->card->update(['name_en' => 'Luffy']);
        expect(queuedPrintingIds())->toBe([$printing->id]);

        Queue::fake();
        $printing->card->delete();
        expect(queuedPrintingIds(RemoveFromSearch::class))->toBe([$printing->id])
            ->and(queuedPrintingIds())->toBe([]);
    });

    test('editing a set re-indexes the set and its printings', function () {
        $printing = indexedPrinting();

        Queue::fake();
        $printing->set->update(['name_en' => 'Renamed']);

        expect(queuedPrintingIds())->toContain($printing->id);
        expect(Queue::pushed(MakeSearchable::class)->contains(fn (object $queued) => $queued->models->first() instanceof CardSet))->toBeTrue();
    });

    test('deactivating a game removes its printings and its sets from the index', function () {
        $printing = indexedPrinting();

        Queue::fake();
        $printing->set->game->update(['is_active' => false]);

        expect(queuedPrintingIds(RemoveFromSearch::class))->toBe([$printing->id])
            ->and(Queue::pushed(RemoveFromSearch::class)->contains(fn (object $queued) => $queued->models->first() instanceof CardSet))->toBeTrue();
    });

    test('a stock drop inside withoutSyncingToSearch queues no search job and no alert check', function () {
        $printing = indexedPrinting();
        $variant = Variant::factory()->for($printing)->create(['qty' => 1]);

        Queue::fake();
        Printing::withoutSyncingToSearch(fn () => $variant->update(['qty' => 0]));

        Queue::assertNothingPushed();
    });
});
