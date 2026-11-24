<?php

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\User;
use App\Models\Variant;
use App\Models\VariantPhoto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('condition sort runs from best to worst', function () {
    $ordered = collect(CardCondition::cases())->sortBy(fn (CardCondition $condition) => $condition->sort());

    expect($ordered->map(fn (CardCondition $condition) => $condition->value)->values()->all())
        ->toBe(['nm', 'lp', 'mp', 'hp', 'dmg'])
        ->and(CardCondition::Nm->sort())->toBe(1)
        ->and(CardCondition::Dmg->sort())->toBe(5);
});

test('a variant sku is built from set code, number, language, condition and finish', function () {
    $set = CardSet::factory()->create(['code' => 'OP-05']);
    $printing = Printing::factory()->for($set, 'set')->create(['number' => '119', 'language' => Language::Ja]);

    $foil = Variant::factory()->foil()->for($printing)->create(['condition' => CardCondition::Nm]);
    $normal = Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp]);

    expect($foil->sku)->toBe('OP-05-119-JA-NM-F')
        ->and($normal->sku)->toBe('OP-05-119-JA-LP-N');
});

test('characters outside A-Z, 0-9 and the dash become underscores in a sku', function () {
    $set = CardSet::factory()->create(['code' => 'sv3pt5']);
    $printing = Printing::factory()->for($set, 'set')->create(['number' => '215/197']);

    $variant = Variant::factory()->for($printing)->create();

    expect($variant->sku)->toBe('SV3PT5-215_197-EN-NM-N');
});

test('an explicit sku is kept', function () {
    $variant = Variant::factory()->create(['sku' => 'CUSTOM-1']);

    expect($variant->sku)->toBe('CUSTOM-1');
});

test('a second card with the same name gets a -2 slug, even when the first is trashed', function () {
    $game = Game::factory()->create();

    $first = Card::factory()->for($game)->create(['name_en' => 'Charizard ex']);
    $second = Card::factory()->for($game)->create(['name_en' => 'Charizard ex']);
    $first->delete();
    $third = Card::factory()->for($game)->create(['name_en' => 'Charizard ex']);

    expect($first->slug)->toBe('charizard-ex')
        ->and($second->slug)->toBe('charizard-ex-2')
        ->and($third->slug)->toBe('charizard-ex-3');
});

test('a game slug is generated when blank', function () {
    $game = Game::factory()->create(['slug' => null, 'name_en' => 'One Piece']);

    expect($game->slug)->toBe('one-piece');
});

test('a printing cannot have the same condition and finish twice', function () {
    $printing = Printing::factory()->create();

    // Distinct skus, so only the (printing, condition, finish) index can refuse the second row.
    Variant::factory()->for($printing)->create(['sku' => 'FIRST']);
    Variant::factory()->for($printing)->create(['sku' => 'SECOND']);
})->throws(UniqueConstraintViolationException::class);

test('the same condition in the other finish is allowed', function () {
    $printing = Printing::factory()->withFoil()->create();

    Variant::factory()->for($printing)->create();
    Variant::factory()->foil()->for($printing)->create();

    expect($printing->variants()->count())->toBe(2);
});

test('a set number is unique per set and language', function () {
    $set = CardSet::factory()->create();

    Printing::factory()->for($set, 'set')->create(['number' => '1', 'language' => Language::En]);
    Printing::factory()->for($set, 'set')->create(['number' => '1', 'language' => Language::Ja]);

    expect($set->printings()->count())->toBe(2);

    Printing::factory()->for($set, 'set')->create(['number' => '1', 'language' => Language::En]);
})->throws(UniqueConstraintViolationException::class);

test('unpriced variants have a null price and factories otherwise always price', function () {
    expect(Variant::factory()->unpriced()->create()->price_satang)->toBeNull()
        ->and(Variant::factory()->create()->price_satang)->toBeInt();
});

test('printings can be limited to a language', function () {
    $card = Card::factory()->create();
    Printing::factory()->for($card)->create(['language' => Language::Ja]);
    Printing::factory()->for($card)->create(['language' => Language::En]);

    expect($card->printings()->forLanguage(Language::Ja)->count())->toBe(1);
});

test('relations run both ways along the chain', function () {
    $variant = Variant::factory()->create();

    expect($variant->printing->card->game)->toBeInstanceOf(Game::class)
        ->and($variant->printing->set->game)->toBeInstanceOf(Game::class)
        ->and($variant->printing->variants->first()->is($variant))->toBeTrue()
        ->and($variant->printing->set->printings->first()->is($variant->printing))->toBeTrue()
        ->and($variant->printing->card->printings->first()->is($variant->printing))->toBeTrue();
});

test('deleting a variant removes its photos', function () {
    $photo = VariantPhoto::factory()->create();

    $photo->variant->delete();

    expect(VariantPhoto::count())->toBe(0);
});

test('catalogue policies allow staff and refuse customers', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->create();
    $variant = Variant::factory()->create();

    expect($staff->can('viewAny', Variant::class))->toBeTrue()
        ->and($staff->can('update', $variant))->toBeTrue()
        ->and($customer->can('viewAny', Variant::class))->toBeFalse()
        ->and($customer->can('update', $variant))->toBeFalse()
        ->and($customer->can('delete', $variant->printing->card))->toBeFalse();
});

test('an enum label is the Thai string, not the translation key', function () {
    expect(CardCondition::Nm->getLabel())->toBe('สภาพใหม่ (NM)')
        ->and(Finish::Foil->getLabel())->toBe('ฟอยล์')
        ->and(Language::Ja->getLabel())->toBe('ญี่ปุ่น');
});

test('a taken sku gets a numeric suffix', function () {
    $set = CardSet::factory()->create(['code' => 'OP-05']);

    // Three different numbers that all sanitise to `215_197`.
    $slash = Printing::factory()->for($set, 'set')->create(['number' => '215/197']);
    $underscore = Printing::factory()->for($set, 'set')->create(['number' => '215_197']);
    $star = Printing::factory()->for($set, 'set')->create(['number' => '215*197']);

    $first = Variant::factory()->for($slash)->create();
    $second = Variant::factory()->for($underscore)->create();
    $third = Variant::factory()->for($star)->create();

    expect($first->sku)->toBe('OP-05-215_197-EN-NM-N')
        ->and($second->sku)->toBe('OP-05-215_197-EN-NM-N-2')
        ->and($third->sku)->toBe('OP-05-215_197-EN-NM-N-3');
});

test('a sku for a missing printing is a clear error', function () {
    Variant::factory()->make(['printing_id' => 999999])->save();
})->throws(LogicException::class, 'printing [999999] does not exist');

test('a sku can be built for a soft-deleted printing', function () {
    $printing = Printing::factory()->create();
    $printing->delete();

    $variant = Variant::factory()->for($printing)->create();

    expect($variant->sku)->not->toBeEmpty();
});

test('a name with no ascii slug falls back to card and game', function () {
    $game = Game::factory()->create(['slug' => null, 'name_en' => 'ลิซาร์ดอน']);
    $first = Card::factory()->for($game)->create(['name_en' => 'ลิซาร์ดอน']);
    $second = Card::factory()->for($game)->create(['name_en' => 'リザードン']);

    expect($game->slug)->toBe('game')
        ->and($first->slug)->toBe('card')
        ->and($second->slug)->toBe('card-2');
});
