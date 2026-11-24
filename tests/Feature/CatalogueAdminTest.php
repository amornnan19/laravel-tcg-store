<?php

use App\Actions\GenerateVariants;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Filament\Resources\Cards\CardResource;
use App\Filament\Resources\Cards\Pages\CreateCard;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Filament\Resources\CardSets\CardSetResource;
use App\Filament\Resources\CardSets\Pages\CreateCardSet;
use App\Filament\Resources\CardSets\Pages\EditCardSet;
use App\Filament\Resources\CardSets\Pages\ListCardSets;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGame;
use App\Filament\Resources\Printings\Pages\CreatePrinting;
use App\Filament\Resources\Printings\Pages\EditPrinting;
use App\Filament\Resources\Printings\Pages\ListPrintings;
use App\Filament\Resources\Printings\PrintingResource;
use App\Filament\Resources\Variants\RelationManagers\PhotosRelationManager;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\User;
use App\Models\Variant;
use App\Models\VariantPhoto;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

dataset('catalogue resources', [
    'games' => fn () => GameResource::class,
    'sets' => fn () => CardSetResource::class,
    'cards' => fn () => CardResource::class,
    'printings' => fn () => PrintingResource::class,
]);

test('each catalogue list page renders for staff', function (string $resource) {
    $this->get($resource::getUrl('index'))->assertOk();
})->with('catalogue resources');

test('each catalogue list page is forbidden for a customer', function (string $resource) {
    $this->actingAs(User::factory()->create())
        ->get($resource::getUrl('index'))
        ->assertForbidden();
})->with('catalogue resources');

test('the catalogue resources sit in the Thai navigation group', function () {
    expect(GameResource::getNavigationGroup())->toBe(__('admin.navigation.catalogue'))
        ->and(PrintingResource::getNavigationGroup())->toBe('แคตตาล็อก');
});

test('creating a card through the form generates its slug', function () {
    $game = Game::factory()->create();

    Livewire::test(CreateCard::class)
        ->fillForm([
            'game_id' => $game->getKey(),
            'name_en' => 'Charizard ex',
            'traits' => ['color' => 'red'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $card = Card::query()->firstOrFail();

    expect($card->slug)->toBe('charizard-ex')
        ->and($card->traits)->toBe(['color' => 'red']);
});

test('the cards list searches by english, japanese and thai name', function () {
    $game = Game::factory()->create();
    $english = Card::factory()->for($game)->create(['name_en' => 'Pikachu', 'name_ja' => null, 'name_th' => null]);
    $japanese = Card::factory()->for($game)->create(['name_en' => 'Zebstrika', 'name_ja' => 'リザードン', 'name_th' => null]);
    $thai = Card::factory()->for($game)->create(['name_en' => 'Gengar', 'name_ja' => null, 'name_th' => 'ลิซาร์ดอน']);

    Livewire::test(ListCards::class)
        ->searchTable('Pikachu')->assertCanSeeTableRecords([$english])->assertCanNotSeeTableRecords([$japanese, $thai])
        ->searchTable('リザードン')->assertCanSeeTableRecords([$japanese])->assertCanNotSeeTableRecords([$english, $thai])
        ->searchTable('ลิซาร์ดอน')->assertCanSeeTableRecords([$thai])->assertCanNotSeeTableRecords([$english, $japanese]);
});

test('a trashed card is hidden until the trashed filter is used, then restores', function () {
    $card = Card::factory()->create();
    $card->delete();

    Livewire::test(ListCards::class)
        ->assertCanNotSeeTableRecords([$card])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$card])
        ->callAction(TestAction::make('restore')->table($card));

    expect($card->fresh()->trashed())->toBeFalse();
});

test('a set code must be unique within its game only', function () {
    $game = Game::factory()->create();
    CardSet::factory()->for($game)->create(['code' => 'OP05']);

    Livewire::test(CreateCardSet::class)
        ->fillForm(['game_id' => $game->getKey(), 'code' => 'OP05', 'name_en' => 'Duplicate'])
        ->call('create')
        ->assertHasFormErrors(['code' => 'unique']);

    Livewire::test(CreateCardSet::class)
        ->fillForm(['game_id' => Game::factory()->create()->getKey(), 'code' => 'OP05', 'name_en' => 'Other game'])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('a game saves its filters as a list of facet keys', function () {
    $game = Game::factory()->create(['filters' => ['rarity']]);

    Livewire::test(EditGame::class, ['record' => $game->getKey()])
        ->fillForm(['filters' => ['rarity', 'color', 'language']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($game->fresh()->filters)->toBe(['rarity', 'color', 'language']);
});

test('the printing edit repeater saves a baht price as satang on the existing row', function () {
    $printing = Printing::factory()->create();
    $variant = Variant::factory()->for($printing)->create(['price_satang' => 100, 'qty' => 1]);
    $row = "data.variants.record-{$variant->getKey()}";

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->assertSet("{$row}.price_satang", '1')
        ->set("{$row}.price_satang", '4290.50')
        ->set("{$row}.compare_at_satang", '5000')
        ->set("{$row}.qty", 3)
        ->set("{$row}.has_real_photo", true)
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = Variant::query()->sole();

    expect($saved->getKey())->toBe($variant->getKey())
        ->and($saved->price_satang)->toBe(429050)
        ->and($saved->compare_at_satang)->toBe(500000)
        ->and($saved->qty)->toBe(3)
        ->and($saved->has_real_photo)->toBeTrue()
        ->and(Money::fromSatang($saved->price_satang)->format())->toBe('฿ 4,290.50');
});

test('the printing edit repeater shows satang as baht and a blank price stays null', function () {
    $printing = Printing::factory()->create();
    $variant = Variant::factory()->for($printing)->create(['price_satang' => 429050]);
    $row = "data.variants.record-{$variant->getKey()}";

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->assertSet("{$row}.price_satang", '4290.50')
        ->set("{$row}.price_satang", '')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Variant::query()->sole()->price_satang)->toBeNull();
});

test('the printing repeater rejects a price that is not a baht amount', function (string $price) {
    $printing = Printing::factory()->create();
    $variant = Variant::factory()->for($printing)->create(['price_satang' => 100]);

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->set("data.variants.record-{$variant->getKey()}.price_satang", $price)
        ->call('save')
        ->assertHasFormErrors();

    expect(Variant::query()->sole()->price_satang)->toBe(100);
})->with(['three decimals' => '1.234', 'negative' => '-1', 'letters' => 'abc', 'above the maximum' => '10000000']);

test('the printing repeater rejects a new row that repeats an existing condition and finish', function () {
    $printing = Printing::factory()->create();
    Variant::factory()->for($printing)->create();

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->callFormComponentAction('variants', 'add')
        ->assertFormSet(fn (array $state): bool => count($state['variants']) === 2)
        ->tap(function ($component) {
            $newKey = collect(array_keys($component->get('data.variants')))->first(fn ($key) => ! str_starts_with($key, 'record-'));
            $component->set("data.variants.{$newKey}.condition", 'nm')
                ->set("data.variants.{$newKey}.finish", 'normal')
                ->set("data.variants.{$newKey}.qty", 1);
        })
        ->call('save')
        ->assertHasFormErrors(['variants']);

    expect(Variant::query()->count())->toBe(1);
});

test('the condition and finish of an existing variant row cannot be changed', function () {
    $printing = Printing::factory()->withFoil()->create();
    $variant = Variant::factory()->for($printing)->create();
    $row = "data.variants.record-{$variant->getKey()}";

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->set("{$row}.condition", 'dmg')
        ->set("{$row}.finish", 'foil')
        ->set("{$row}.qty", 4)
        ->call('save')
        ->assertHasNoFormErrors();

    $variant->refresh();

    expect($variant->condition)->toBe(CardCondition::Nm)
        ->and($variant->finish)->toBe(Finish::Normal)
        ->and($variant->qty)->toBe(4);
});

test('a failing save rolls back a variant row removal', function () {
    $printing = Printing::factory()->create();
    $removed = Variant::factory()->for($printing)->create(['condition' => CardCondition::Nm]);
    $kept = Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'qty' => 1]);

    Variant::saving(function (Variant $variant): void {
        if ($variant->qty === 77) {
            throw new RuntimeException('boom');
        }
    });

    $component = Livewire::test(EditPrinting::class, ['record' => $printing->getKey()]);
    $state = $component->get('data.variants');
    unset($state["record-{$removed->getKey()}"]);
    $state["record-{$kept->getKey()}"]['qty'] = 77;

    expect(fn () => $component->set('data.variants', $state)->call('save'))->toThrow(RuntimeException::class);

    expect(Variant::query()->whereKey($removed->getKey())->exists())->toBeTrue()
        ->and($kept->fresh()->qty)->toBe(1);
});

test('removing a variant row asks for confirmation naming the variant', function () {
    $printing = Printing::factory()->create();
    $variant = Variant::factory()->for($printing)->create();

    $component = Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->mountAction(TestAction::make('delete')->schemaComponent('variants')->arguments(['item' => "record-{$variant->getKey()}"]));

    $action = $component->instance()->getMountedAction();

    expect($action->isConfirmationRequired())->toBeTrue()
        ->and((string) $action->getModalHeading())->toBe(__('admin.actions.delete_variant_heading', [
            'name' => CardCondition::Nm->label().' · '.Finish::Normal->label(),
        ]));
});

test('deleting a variant deletes the files of its photos', function () {
    Storage::fake('public');
    Storage::disk('public')->put('variant-photos/a.jpg', 'a');
    Storage::disk('public')->put('variant-photos/b.jpg', 'b');
    Storage::disk('public')->put('variant-photos/other.jpg', 'o');

    $variant = Variant::factory()->create();
    VariantPhoto::factory()->for($variant)->create(['path' => 'variant-photos/a.jpg']);
    VariantPhoto::factory()->for($variant)->create(['path' => 'variant-photos/b.jpg']);
    VariantPhoto::factory()->create(['path' => 'variant-photos/other.jpg']);

    $variant->delete();

    Storage::disk('public')->assertMissing(['variant-photos/a.jpg', 'variant-photos/b.jpg']);
    Storage::disk('public')->assertExists('variant-photos/other.jpg');
    expect(VariantPhoto::query()->count())->toBe(1);
});

test('the variants of a printing are ordered NM to DMG with normal before foil', function () {
    $printing = Printing::factory()->withFoil()->create();
    foreach ([[CardCondition::Dmg, Finish::Normal], [CardCondition::Nm, Finish::Foil], [CardCondition::Lp, Finish::Normal], [CardCondition::Nm, Finish::Normal], [CardCondition::Hp, Finish::Foil]] as [$condition, $finish]) {
        Variant::factory()->for($printing)->create(['condition' => $condition, 'finish' => $finish]);
    }

    expect($printing->variants->map(fn (Variant $v): string => $v->condition->value.'/'.$v->finish->value)->all())
        ->toBe(['nm/normal', 'nm/foil', 'lp/normal', 'hp/foil', 'dmg/normal']);
});

test('a printing whose card is trashed still opens, titles and saves', function () {
    $printing = Printing::factory()->create(['number' => '7']);
    $printing->card->delete();

    expect(PrintingResource::getRecordTitle($printing->fresh()))->toContain($printing->card->name_en);

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->assertSet('data.card_id', $printing->card_id)
        ->fillForm(['rarity_code' => 'sec'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($printing->fresh()->rarity_code)->toBe('sec');

    Livewire::test(ListPrintings::class)->assertCanSeeTableRecords([$printing]);
});

test('a card with live printings cannot be deleted, one without can', function () {
    $withPrinting = Printing::factory()->create();
    $card = $withPrinting->card;
    $lonely = Card::factory()->create();

    Livewire::test(ListCards::class)
        ->assertActionDisabled(TestAction::make('delete')->table($card))
        ->assertActionEnabled(TestAction::make('delete')->table($lonely))
        ->callAction(TestAction::make('delete')->table($lonely));

    expect($card->fresh()->trashed())->toBeFalse()
        ->and($lonely->fresh()->trashed())->toBeTrue();

    $withPrinting->delete();
    Livewire::test(ListCards::class)
        ->assertActionEnabled(TestAction::make('delete')->table($card->fresh()));
});

test('a set whose only printing is trashed cannot be deleted', function () {
    $printing = Printing::factory()->create();
    $printing->delete();
    $empty = CardSet::factory()->create();

    Livewire::test(ListCardSets::class)
        ->assertActionHidden(TestAction::make('delete')->table($printing->set))
        ->assertActionVisible(TestAction::make('delete')->table($empty));

    Livewire::test(EditCardSet::class, ['record' => $printing->set->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditCardSet::class, ['record' => $empty->getKey()])
        ->assertActionVisible('delete');
});

test('generate variants saves unsaved edits first and stops on a validation error', function () {
    $printing = Printing::factory()->create();
    $variant = Variant::factory()->for($printing)->create(['price_satang' => 100, 'condition' => CardCondition::Nm]);
    $row = "data.variants.record-{$variant->getKey()}";

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->set("{$row}.price_satang", '55.50')
        ->callAction('generateVariants')
        ->assertNotified(__('admin.actions.generate_variants_done', ['count' => 4]));

    expect($variant->fresh()->price_satang)->toBe(5550)
        ->and($printing->variants()->count())->toBe(5);

    $bad = Printing::factory()->create();
    $badVariant = Variant::factory()->for($bad)->create(['price_satang' => 100]);

    Livewire::test(EditPrinting::class, ['record' => $bad->getKey()])
        ->set("data.variants.record-{$badVariant->getKey()}.price_satang", 'abc')
        ->callAction('generateVariants');

    expect($bad->variants()->count())->toBe(1)
        ->and($badVariant->fresh()->price_satang)->toBe(100);
});

test('the panel is branded in Thai and uses the Thai font', function () {
    auth()->logout();

    $this->get('/admin/login')->assertOk()->assertSee('หลังร้าน')->assertSee('IBM Plex Sans Thai', false);
});

test('generate variants creates ten rows for a foil printing and five without', function (bool $hasFoil, int $expected) {
    $printing = Printing::factory()->create(['has_foil' => $hasFoil]);

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->callAction('generateVariants')
        ->assertNotified(__('admin.actions.generate_variants_done', ['count' => $expected]));

    expect($printing->variants()->count())->toBe($expected)
        ->and($printing->variants()->whereNotNull('price_satang')->count())->toBe(0)
        ->and($printing->variants()->where('qty', '!=', 0)->count())->toBe(0);
})->with([
    'foil printing' => [true, 10],
    'non-foil printing' => [false, 5],
]);

test('generate variants creates nothing on a second run and only the missing rows after a delete', function () {
    $printing = Printing::factory()->withFoil()->create();
    $generate = app(GenerateVariants::class);

    expect($generate->handle($printing))->toBe(10)
        ->and($generate->handle($printing))->toBe(0);

    $printing->variants()->where('condition', CardCondition::Lp)->where('finish', Finish::Foil)->delete();

    expect($generate->handle($printing))->toBe(1)
        ->and($printing->variants()->count())->toBe(10);

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->callAction('generateVariants')
        ->assertNotified(__('admin.actions.generate_variants_done', ['count' => 0]));
});

test('generate variants is not offered on a trashed printing', function () {
    $printing = Printing::factory()->create();
    $printing->delete();

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->assertActionHidden('generateVariants');

    expect($printing->variants()->count())->toBe(0);
});

test('a new printing that matches a trashed one is told to restore it instead', function () {
    $trashed = Printing::factory()->create(['number' => '119', 'language' => Language::Ja]);
    $trashed->delete();

    Livewire::test(CreatePrinting::class)
        ->fillForm([
            'card_id' => Card::factory()->create()->getKey(),
            'set_id' => $trashed->set_id,
            'number' => '119',
            'language' => 'ja',
            'rarity_code' => 'r',
        ])
        ->call('create')
        ->assertHasFormErrors(['number'])
        ->assertSee(__('admin.validation.printing_trashed'));

    expect(Printing::withTrashed()->count())->toBe(1);
});

test('a new printing that matches a live one gets the plain taken message', function () {
    $live = Printing::factory()->create(['number' => '119', 'language' => Language::Ja]);

    Livewire::test(CreatePrinting::class)
        ->fillForm([
            'card_id' => Card::factory()->create()->getKey(),
            'set_id' => $live->set_id,
            'number' => '119',
            'language' => 'ja',
            'rarity_code' => 'r',
        ])
        ->call('create')
        ->assertHasFormErrors(['number'])
        ->assertSee(__('admin.validation.printing_taken'))
        ->assertDontSee(__('admin.validation.printing_trashed'));
});

test('a printing can be created when only the language differs from a trashed one', function () {
    $trashed = Printing::factory()->create(['number' => '119', 'language' => Language::Ja]);
    $trashed->delete();

    Livewire::test(CreatePrinting::class)
        ->fillForm([
            'card_id' => Card::factory()->create()->getKey(),
            'set_id' => $trashed->set_id,
            'number' => '119',
            'language' => 'en',
            'rarity_code' => 'r',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Printing::query()->count())->toBe(1);
});

test('editing a printing does not collide with itself', function () {
    $printing = Printing::factory()->create(['number' => '7']);

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->fillForm(['rarity_code' => 'sec'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($printing->fresh()->rarity_code)->toBe('sec');
});

test('the printings list filters by game, set and language', function () {
    $matching = Printing::factory()->create(['language' => Language::Ja]);
    $otherLanguage = Printing::factory()->create(['set_id' => $matching->set_id, 'language' => Language::En]);
    $otherSet = Printing::factory()->create(['language' => Language::Ja]);

    Livewire::test(ListPrintings::class)
        ->filterTable('language', 'ja')
        ->assertCanSeeTableRecords([$matching, $otherSet])
        ->assertCanNotSeeTableRecords([$otherLanguage])
        ->filterTable('set_id', $matching->set_id)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$otherSet])
        ->resetTableFilters()
        ->filterTable('game', $matching->set->game_id)
        ->assertCanSeeTableRecords([$matching, $otherLanguage])
        ->assertCanNotSeeTableRecords([$otherSet]);
});

test('the variant photos relation manager lists the photos of its variant in sort order', function () {
    $variant = Variant::factory()->create();
    $second = VariantPhoto::factory()->for($variant)->create(['sort' => 2]);
    $first = VariantPhoto::factory()->for($variant)->create(['sort' => 1]);
    $other = VariantPhoto::factory()->create();

    Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $variant, 'pageClass' => EditPrinting::class])
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertCanNotSeeTableRecords([$other]);

    expect(PhotosRelationManager::getTitle($variant, EditPrinting::class))->toBe('รูปถ่ายจริง');
});

test('after generating variants the form shows the new rows and a save keeps them', function () {
    $printing = Printing::factory()->withFoil()->create();
    Variant::factory()->for($printing)->create(['condition' => CardCondition::Nm, 'finish' => Finish::Normal]);

    $component = Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->callAction('generateVariants')
        ->assertNotified(__('admin.actions.generate_variants_done', ['count' => 9]));

    expect(Variant::query()->count())->toBe(10)
        ->and($component->get('data.variants'))->toHaveCount(10);

    $component->call('save')->assertHasNoFormErrors();

    expect(Variant::query()->count())->toBe(10);
});

test('the printing card relation stays plain while the admin one includes a trashed card', function () {
    $printing = Printing::factory()->create();
    $printing->card->delete();
    $fresh = Printing::query()->findOrFail($printing->getKey());

    expect($fresh->card)->toBeNull()
        ->and($fresh->cardWithTrashed?->trashed())->toBeTrue();
});

test('the card select rejects a trashed card on create but an existing printing keeps its trashed card', function () {
    $trashedCard = Card::factory()->create();
    $trashedCard->delete();
    $set = CardSet::factory()->create();

    Livewire::test(CreatePrinting::class)
        ->fillForm(['card_id' => $trashedCard->getKey(), 'set_id' => $set->getKey(), 'number' => '1', 'language' => 'en', 'rarity_code' => 'r'])
        ->call('create')
        ->assertHasFormErrors(['card_id']);

    expect(Printing::query()->count())->toBe(0);

    $printing = Printing::factory()->create();
    $printing->card->delete();

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->fillForm(['rarity_code' => 'sec'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($printing->fresh()->rarity_code)->toBe('sec');

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->fillForm(['card_id' => $trashedCard->getKey()])
        ->call('save')
        ->assertHasFormErrors(['card_id']);
});

test('a printing whose card is trashed cannot be restored until the card is', function () {
    $printing = Printing::factory()->create();
    $card = $printing->card;
    $printing->delete();
    $card->delete();

    Livewire::test(ListPrintings::class)
        ->filterTable('trashed', true)
        ->assertActionDisabled(TestAction::make('restore')->table($printing));

    Livewire::test(EditPrinting::class, ['record' => $printing->getKey()])
        ->assertActionDisabled('restore');

    $card->restore();

    Livewire::test(ListPrintings::class)
        ->filterTable('trashed', true)
        ->assertActionEnabled(TestAction::make('restore')->table($printing->fresh()));
});

test('a photo file survives a rolled-back delete and goes after a committed one', function () {
    Storage::fake('public');
    Storage::disk('public')->put('variant-photos/a.jpg', 'a');
    $variant = Variant::factory()->create();
    VariantPhoto::factory()->for($variant)->create(['path' => 'variant-photos/a.jpg']);

    try {
        DB::transaction(function () use ($variant) {
            $variant->delete();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Storage::disk('public')->assertExists('variant-photos/a.jpg');
    expect(VariantPhoto::query()->count())->toBe(1);

    Variant::query()->findOrFail($variant->getKey())->delete();

    Storage::disk('public')->assertMissing('variant-photos/a.jpg');
});

test('the cards list computes has-printings once, not per row', function () {
    Card::factory()->count(4)->create()->each(fn (Card $card) => Printing::factory()->for($card)->create());

    DB::enableQueryLog();
    Livewire::test(ListCards::class)->assertSuccessful();
    $perRow = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'printings') && ! str_contains($sql, 'from "cards"'));

    expect($perRow)->toBeEmpty();
});

test('a list page renders the Thai results text and Thai boolean labels', function () {
    Printing::factory()->count(2)->create();

    Livewire::test(ListPrintings::class)
        ->assertSee('2 รายการ')
        ->assertDontSee('2 results')
        ->assertSee('ไม่ใช่');
});
