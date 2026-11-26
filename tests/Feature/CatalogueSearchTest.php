<?php

use App\Enums\QueryKind;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\SealedProduct;
use App\Models\Variant;
use App\Search\CatalogueSearch;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Meilisearch\Client as MeilisearchClient;

uses(RefreshDatabase::class);

/**
 * @return array{set: CardSet, soldOut: Printing, inStock: Printing, other: Printing}
 */
function searchCatalogue(): array
{
    $game = Game::factory()->create(['slug' => 'one-piece', 'is_active' => true]);
    $set = CardSet::factory()->for($game)->create(['code' => 'OP-05', 'name_en' => 'Awakening of the New Era', 'sort' => 1]);
    $otherSet = CardSet::factory()->for($game)->create(['code' => 'OP-06', 'name_en' => 'Wings of the Captain', 'sort' => 2]);

    $luffy = Card::factory()->for($game)->create(['name_en' => 'Monkey D. Luffy', 'name_th' => 'ลูฟี่']);
    $zoro = Card::factory()->for($game)->create(['name_en' => 'Roronoa Zoro']);

    $soldOut = Printing::factory()->for($luffy)->for($set, 'set')->create(['number' => '001']);
    $inStock = Printing::factory()->for($zoro)->for($set, 'set')->create(['number' => '002']);
    $other = Printing::factory()->for($luffy)->for($otherSet, 'set')->create(['number' => '010']);

    Variant::factory()->for($soldOut)->create(['qty' => 0, 'price_satang' => 10000]);
    Variant::factory()->for($inStock)->create(['qty' => 3, 'price_satang' => 50000]);
    Variant::factory()->for($other)->create(['qty' => 1, 'price_satang' => 20000]);

    return compact('set', 'soldOut', 'inStock', 'other');
}

beforeEach(function () {
    config()->set('scout.driver', 'collection');
});

function throwingEngine(): void
{
    app(EngineManager::class)->extend('throwing', fn () => new class extends NullEngine
    {
        public function search(Builder $builder): never
        {
            throw new RuntimeException('Meilisearch is down');
        }
    });
    config()->set('scout.driver', 'throwing');
}

test('a card name returns printings and no jump', function () {
    $catalogue = searchCatalogue();

    $result = app(CatalogueSearch::class)->autocomplete('Luffy');

    expect($result->jump)->toBeNull()
        ->and($result->limited)->toBeFalse()
        ->and($result->printings->pluck('id')->all())->toEqualCanonicalizing([$catalogue['soldOut']->id, $catalogue['other']->id]);
});

test('a set code adds a jump row and never replaces the text results', function () {
    $catalogue = searchCatalogue();
    $war = CardSet::factory()->for($catalogue['set']->game)->create(['code' => 'WAR', 'name_en' => 'War of the Best']);
    $warRoom = Printing::factory()->for(Card::factory()->create(['name_en' => 'War Room']), 'card')->for($catalogue['set'], 'set')->create();

    $result = app(CatalogueSearch::class)->autocomplete('war');

    expect($result->jump->kind)->toBe(QueryKind::Set)
        ->and($result->jump->setIds)->toBe([$war->id])
        ->and($result->printings->pluck('id')->all())->toBe([$warRoom->id])
        ->and($result->sets->pluck('id')->all())->toBe([$war->id]);
});

test('a card code puts its printings first, then the text results, without duplicates', function () {
    $catalogue = searchCatalogue();
    $catalogue['inStock']->card->update(['name_en' => 'OP05 2 Legend']);
    $alternate = Printing::factory()->for(Card::factory()->create(['name_en' => 'OP05 2 Alternate']), 'card')->for($catalogue['other']->set, 'set')->create();

    $result = app(CatalogueSearch::class)->autocomplete('OP05 2');

    expect($result->jump->kind)->toBe(QueryKind::Printing)
        ->and($result->jump->printingIds)->toBe([$catalogue['inStock']->id])
        ->and($result->printings->pluck('id')->all())->toBe([$catalogue['inStock']->id, $alternate->id]);
});

test('sets are matched by name and capped at two, printings at six', function () {
    $catalogue = searchCatalogue();
    CardSet::factory()->for($catalogue['set']->game)->count(3)->create(['name_en' => 'Awakening Reprint']);
    $card = Card::factory()->create(['name_en' => 'Awakening Hero']);
    Printing::factory()->for($card)->for($catalogue['set'], 'set')->count(8)->create();

    $result = app(CatalogueSearch::class)->autocomplete('Awakening');

    expect($result->sets)->toHaveCount(2)
        ->and($result->printings)->toHaveCount(6);
});

test('an empty query finds nothing and does not jump', function () {
    searchCatalogue();

    $result = app(CatalogueSearch::class)->autocomplete('');

    expect($result->jump)->toBeNull()->and($result->sets)->toBeEmpty()->and($result->printings)->toBeEmpty();
});

test('an inactive game or a trashed card is never a destination or a result', function (bool $engineDown) {
    $catalogue = searchCatalogue();
    $engineDown && throwingEngine();

    $catalogue['inStock']->card->delete();
    $trashed = app(CatalogueSearch::class)->autocomplete('OP05-002');

    expect($trashed->jump->kind)->toBe(QueryKind::Set)
        ->and($trashed->printings->pluck('id')->all())->not->toContain($catalogue['inStock']->id);

    $catalogue['set']->game->update(['is_active' => false]);
    app()->forgetScopedInstances();
    $inactive = app(CatalogueSearch::class)->autocomplete('OP-05');

    expect($inactive->jump)->toBeNull()
        ->and($inactive->sets)->toBeEmpty()
        ->and($inactive->printings)->toBeEmpty()
        ->and(app(CatalogueSearch::class)->autocomplete('Awakening')->sets)->toBeEmpty();
})->with(['collection engine' => false, 'fallback' => true]);

test('when the engine throws the database answers, limited, and the failure is reported', function () {
    $catalogue = searchCatalogue();
    throwingEngine();

    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    $result = app(CatalogueSearch::class)->autocomplete('luffy');

    expect($result->limited)->toBeTrue()
        ->and($result->printings->pluck('id')->all())->toEqualCanonicalizing([$catalogue['soldOut']->id, $catalogue['other']->id])
        ->and($reported)->toBe(['Meilisearch is down']);
});

test('the fallback matches Thai names and set codes', function () {
    $catalogue = searchCatalogue();
    throwingEngine();

    $thai = app(CatalogueSearch::class)->autocomplete('ลูฟี่');
    $byCode = app(CatalogueSearch::class)->autocomplete('OP-06');

    expect($thai->printings->pluck('id')->all())->toEqualCanonicalizing([$catalogue['soldOut']->id, $catalogue['other']->id])
        ->and($byCode->limited)->toBeTrue()
        ->and($byCode->printings->pluck('id')->all())->toBe([$catalogue['other']->id])
        ->and($byCode->sets->pluck('id')->all())->toBe([$catalogue['other']->set_id]);
});

test('the fallback still resolves a card code, in stock first', function () {
    $catalogue = searchCatalogue();
    $soldOutTwin = Printing::factory()->for($catalogue['inStock']->card)->for($catalogue['set'], 'set')->create(['number' => '002/100']);
    Variant::factory()->for($soldOutTwin)->create(['qty' => 0]);
    throwingEngine();

    $result = app(CatalogueSearch::class)->autocomplete('OP05 2');

    expect($result->limited)->toBeTrue()
        ->and($result->printings->pluck('id')->all())->toBe([$catalogue['inStock']->id, $soldOutTwin->id]);
});

test('the Meilisearch client fails fast instead of hanging', function () {
    $client = app(MeilisearchClient::class);

    $adapter = (new ReflectionProperty($client, 'http'))->getValue($client);
    $guzzle = (new ReflectionProperty($adapter, 'http'))->getValue($adapter);
    $options = (new ReflectionProperty($guzzle, 'config'))->getValue($guzzle);

    expect($options['timeout'])->toBe(2.0)->and($options['connect_timeout'])->toBe(1.0);

    config()->set('scout.meilisearch.timeout', 0.5);
    config()->set('scout.meilisearch.connect_timeout', 0.25);
    app()->forgetInstance(MeilisearchClient::class);

    $client = app(MeilisearchClient::class);
    $adapter = (new ReflectionProperty($client, 'http'))->getValue($client);
    $guzzle = (new ReflectionProperty($adapter, 'http'))->getValue($adapter);
    $options = (new ReflectionProperty($guzzle, 'config'))->getValue($guzzle);

    expect($options['timeout'])->toBe(0.5)->and($options['connect_timeout'])->toBe(0.25);
});

test('Meilisearch ranks in stock printings first', function () {
    try {
        $reachable = Http::timeout(1)->get('http://127.0.0.1:7700/health')->successful();
    } catch (Throwable) {
        $reachable = false;
    }

    if (! $reachable) {
        $this->markTestSkipped('Meilisearch is not running on 127.0.0.1:7700.');
    }

    config()->set('scout.driver', 'meilisearch');
    config()->set('scout.prefix', 'test_'.bin2hex(random_bytes(3)).'_');
    config()->set('scout.queue', false);
    config()->set('scout.after_commit', false);

    $client = app(MeilisearchClient::class);
    $indexes = [(new Printing)->searchableAs(), (new CardSet)->searchableAs(), (new SealedProduct)->searchableAs()];

    try {
        $catalogue = searchCatalogue();

        // In stock but unpriced: only the in_stock rule, not from_satang, ranks it above the sold out printing.
        $unpriced = Printing::factory()->for($catalogue['inStock']->card)->for($catalogue['set'], 'set')->create(['number' => '003']);
        Variant::factory()->for($unpriced)->unpriced()->create(['qty' => 2]);

        $tasks = [];

        foreach ([Printing::class, CardSet::class, SealedProduct::class] as $model) {
            $name = (new $model)->searchableAs();
            $tasks[] = $client->createIndex($name, ['primaryKey' => 'id'])['taskUid'];
            $tasks[] = $client->index($name)->updateSettings(config('scout.meilisearch.index-settings')[$model])['taskUid'];
        }

        $client->waitForTasks($tasks);

        $documents = Printing::query()->with(['card', 'set.game', 'variants'])->get()
            ->filter(fn (Printing $printing) => $printing->shouldBeSearchable())
            ->map(fn (Printing $printing) => $printing->toSearchableArray())->values()->all();

        $client->waitForTasks([
            $client->index($indexes[0])->addDocuments($documents)['taskUid'],
            $client->index($indexes[1])->addDocuments(CardSet::with('game')->get()->map->toSearchableArray()->all())['taskUid'],
        ]);

        $result = app(CatalogueSearch::class)->autocomplete('OP-05');

        expect($result->limited)->toBeFalse()
            ->and($result->jump->kind)->toBe(QueryKind::Set)
            ->and($result->printings->pluck('id')->take(3)->all())->toBe([$catalogue['inStock']->id, $unpriced->id, $catalogue['soldOut']->id]);
    } finally {
        foreach ($indexes as $index) {
            $client->deleteIndex($index);
        }
    }
})->group('meilisearch');
