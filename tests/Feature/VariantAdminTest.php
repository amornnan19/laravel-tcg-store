<?php

use App\Actions\BulkEditVariants;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Filament\Imports\VariantImporter;
use App\Filament\Resources\Variants\Pages\EditVariant;
use App\Filament\Resources\Variants\Pages\ListVariants;
use App\Filament\Resources\Variants\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Variants\VariantResource;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
});

test('the variants list renders for staff and is forbidden for a customer', function () {
    $this->get(VariantResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->create())
        ->get(VariantResource::getUrl('index'))
        ->assertForbidden();
});

test('the variants resource sits in the catalogue group with Thai labels and cannot create', function () {
    expect(VariantResource::getNavigationGroup())->toBe('แคตตาล็อก')
        ->and(VariantResource::getPluralModelLabel())->toBe('ตัวเลือกสินค้า')
        ->and(VariantResource::canCreate())->toBeFalse();
});

test('the price column shows baht and an edit stores satang', function () {
    $variant = Variant::factory()->create(['price_satang' => 429050]);

    Livewire::test(ListVariants::class)
        ->assertTableColumnStateSet('price_satang', '4290.50', $variant)
        ->call('updateTableColumnState', 'price_satang', (string) $variant->getKey(), '1234.5')
        ->assertOk();

    expect($variant->fresh()->price_satang)->toBe(123450);
});

test('a blank price column edit clears the price to null', function () {
    $variant = Variant::factory()->create(['price_satang' => 5000]);

    Livewire::test(ListVariants::class)
        ->call('updateTableColumnState', 'price_satang', (string) $variant->getKey(), '');

    expect($variant->fresh()->price_satang)->toBeNull();
});

test('the price column rejects anything that is not a baht amount', function (string $input) {
    $variant = Variant::factory()->create(['price_satang' => 5000]);

    $response = Livewire::test(ListVariants::class)
        ->call('updateTableColumnState', 'price_satang', (string) $variant->getKey(), $input)
        ->effects['returns'][0] ?? null;

    expect($response)->toHaveKey('error')
        ->and($variant->fresh()->price_satang)->toBe(5000);
})->with(['1.234', '-1', 'abc', '99999999']);

test('the qty column stores an integer and rejects negatives and text', function () {
    $variant = Variant::factory()->create(['qty' => 3]);
    $key = (string) $variant->getKey();

    Livewire::test(ListVariants::class)->call('updateTableColumnState', 'qty', $key, '12');
    expect($variant->fresh()->qty)->toBe(12);

    foreach (['-1', 'abc', '1.5', ''] as $input) {
        $returns = Livewire::test(ListVariants::class)
            ->call('updateTableColumnState', 'qty', $key, $input)
            ->effects['returns'][0] ?? null;

        expect($returns)->toHaveKey('error');
    }

    expect($variant->fresh()->qty)->toBe(12);
});

test('the list filters by set, game, language, rarity, condition, unpriced and out of stock', function () {
    $game = Game::factory()->create();
    $set = CardSet::factory()->for($game)->create();
    $printing = Printing::factory()->for($set, 'set')->create(['language' => Language::Ja, 'rarity_code' => 'sec']);
    $match = Variant::factory()->for($printing)->unpriced()->create(['condition' => CardCondition::Lp, 'qty' => 0]);
    $other = Variant::factory()->create(['condition' => CardCondition::Nm, 'qty' => 5]);

    $filters = [
        ['game', $game->getKey()],
        ['set', $set->getKey()],
        ['language', 'ja'],
        ['rarity', 'sec'],
        ['condition', 'lp'],
    ];

    foreach ($filters as [$name, $value]) {
        Livewire::test(ListVariants::class)
            ->filterTable($name, $value)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    foreach (['unpriced', 'out_of_stock'] as $name) {
        Livewire::test(ListVariants::class)
            ->filterTable($name, true)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

test('the list searches by card name and sku', function () {
    $card = Card::factory()->create(['name_en' => 'Zzyzx Dragon']);
    $match = Variant::factory()->for(Printing::factory()->for($card))->create();
    $other = Variant::factory()->create();

    Livewire::test(ListVariants::class)
        ->searchTable('Zzyzx')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable($other->sku)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$match]);
});

test('a variant of a trashed printing and trashed card still lists', function () {
    $card = Card::factory()->create();
    $printing = Printing::factory()->for($card)->create();
    $variant = Variant::factory()->for($printing)->create();
    $printing->delete();
    $card->delete();

    Livewire::test(ListVariants::class)
        ->assertCanSeeTableRecords([$variant])
        ->assertTableColumnStateSet('printingWithTrashed.cardWithTrashed.name_en', $card->name_en, $variant);
});

test('the list query count does not grow with the number of rows', function () {
    $queriesFor = function (int $rows): int {
        Variant::query()->delete();
        Variant::factory()->count($rows)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListVariants::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $queriesFor(2);
    $many = $queriesFor(20);

    expect($many)->toBe($few);
});

test('set price bulk action sets every selected price in satang and confirms with the count', function () {
    $variants = Variant::factory()->count(3)->create(['price_satang' => 100]);
    $untouched = Variant::factory()->create(['price_satang' => 100]);

    $component = Livewire::test(ListVariants::class)
        ->mountTableBulkAction('set_price', $variants);

    expect($component->instance()->getMountedAction()->getModalHeading())->toBe('ตั้งราคาตัวเลือกสินค้า 3 รายการที่เลือก');

    $component
        ->setTableBulkActionData(['price' => '250.50'])
        ->callMountedAction()
        ->assertNotified(__('admin.bulk.done', ['count' => 3]));

    expect($variants->map(fn ($variant) => $variant->fresh()->price_satang)->unique()->all())->toBe([25050])
        ->and($untouched->fresh()->price_satang)->toBe(100);
});

test('adjust price rounds half up in basis points, skips unpriced and floors at zero', function () {
    $priced = Variant::factory()->create(['price_satang' => 9999]);
    $unpriced = Variant::factory()->unpriced()->create(['condition' => CardCondition::Lp]);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('adjust_price', [$priced, $unpriced], ['percent' => '7.5'])
        ->assertNotified(__('admin.bulk.done', ['count' => 1]));

    expect($priced->fresh()->price_satang)->toBe(10749)
        ->and($unpriced->fresh()->price_satang)->toBeNull();

    $cheap = Variant::factory()->create(['price_satang' => 5000, 'condition' => CardCondition::Mp]);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('adjust_price', [$cheap], ['percent' => '-150']);

    expect($cheap->fresh()->price_satang)->toBe(0);
});

test('the percentage parses to basis points with string math', function (string $percent, int $basisPoints) {
    expect(BulkEditVariants::basisPoints($percent))->toBe($basisPoints);
})->with([['7.5', 750], ['-10', -1000], ['0.05', 5], ['100', 10000], ['12.34', 1234]]);

test('adjust price rejects a malformed percentage', function (string $percent) {
    $variant = Variant::factory()->create(['price_satang' => 5000]);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('adjust_price', [$variant], ['percent' => $percent])
        ->assertHasActionErrors(['percent']);

    expect($variant->fresh()->price_satang)->toBe(5000);
})->with(['abc', '1.234', '', '1e3']);

test('set qty and add qty, which may be negative and floors at zero', function () {
    $a = Variant::factory()->create(['qty' => 4]);
    $b = Variant::factory()->create(['qty' => 1, 'condition' => CardCondition::Lp]);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('set_qty', [$a, $b], ['qty' => 7]);

    expect($a->fresh()->qty)->toBe(7)->and($b->fresh()->qty)->toBe(7);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('add_qty', [$a, $b], ['delta' => 3]);

    expect($a->fresh()->qty)->toBe(10);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('add_qty', [$a, $b], ['delta' => -20]);

    expect($a->fresh()->qty)->toBe(0)->and($b->fresh()->qty)->toBe(0);
});

test('set qty rejects a negative number', function () {
    $variant = Variant::factory()->create(['qty' => 4]);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('set_qty', [$variant], ['qty' => -1])
        ->assertHasActionErrors(['qty']);

    expect($variant->fresh()->qty)->toBe(4);
});

test('mark real photo taken sets the flag on the selection only', function () {
    $selected = Variant::factory()->create(['has_real_photo' => false]);
    $other = Variant::factory()->create(['has_real_photo' => false]);

    Livewire::test(ListVariants::class)
        ->callTableBulkAction('mark_real_photo', [$selected], []);

    expect($selected->fresh()->has_real_photo)->toBeTrue()
        ->and($other->fresh()->has_real_photo)->toBeFalse();
});

test('every bulk action asks for confirmation and names the count', function (string $action) {
    $variants = Variant::factory()->count(2)->sequence(
        ['condition' => CardCondition::Nm],
        ['condition' => CardCondition::Lp],
    )->create();

    $component = Livewire::test(ListVariants::class)->mountTableBulkAction($action, $variants);

    expect($component->instance()->getMountedAction()->getModalHeading())->toContain('2 รายการที่เลือก')
        ->and($component->instance()->getMountedAction()->isConfirmationRequired())->toBeTrue();
})->with(['set_price', 'adjust_price', 'set_qty', 'add_qty', 'mark_real_photo']);

test('the variant edit page saves price and qty and carries the photos relation manager', function () {
    $variant = Variant::factory()->create(['price_satang' => 100, 'qty' => 1]);

    expect(VariantResource::getRelations())->toBe([PhotosRelationManager::class]);

    Livewire::test(EditVariant::class, ['record' => $variant->getKey()])
        ->assertSeeLivewire(PhotosRelationManager::class)
        ->assertSet('data.price_satang', '1')
        ->fillForm(['price_satang' => '4290.50', 'qty' => 9, 'has_real_photo' => true, 'compare_at_satang' => '5000'])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = $variant->fresh();

    expect($saved->price_satang)->toBe(429050)
        ->and($saved->compare_at_satang)->toBe(500000)
        ->and($saved->qty)->toBe(9)
        ->and($saved->has_real_photo)->toBeTrue();
});

test('the edit page cannot change condition or finish', function () {
    $variant = Variant::factory()->create(['condition' => CardCondition::Nm, 'finish' => Finish::Normal]);

    Livewire::test(EditVariant::class, ['record' => $variant->getKey()])
        ->set('data.condition', 'dmg')
        ->set('data.finish', 'foil')
        ->call('save');

    expect($variant->fresh()->condition)->toBe(CardCondition::Nm)
        ->and($variant->fresh()->finish)->toBe(Finish::Normal);
});

test('the importer updates a variant by sku', function () {
    $variant = Variant::factory()->create(['price_satang' => 100, 'qty' => 1]);

    VariantImporter::test()
        ->import(['sku' => $variant->sku, 'price' => '4290.50', 'qty' => '6'])
        ->assertImported();

    expect($variant->fresh()->price_satang)->toBe(429050)
        ->and($variant->fresh()->qty)->toBe(6);
});

test('the importer updates a variant by its composite key when the sku is blank', function () {
    $set = CardSet::factory()->create(['code' => 'OP05']);
    $printing = Printing::factory()->for($set, 'set')->create(['number' => '119', 'language' => Language::Ja]);
    $target = Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'finish' => Finish::Foil, 'price_satang' => 100]);
    $sibling = Variant::factory()->for($printing)->create(['condition' => CardCondition::Nm, 'finish' => Finish::Foil, 'price_satang' => 100]);

    VariantImporter::test()
        ->import([
            'sku' => '', 'set_code' => 'OP05', 'number' => '119', 'language' => 'JA',
            'condition' => 'LP', 'finish' => 'foil', 'price' => '99', 'qty' => '',
        ])
        ->assertImported();

    expect($target->fresh()->price_satang)->toBe(9900)
        ->and($target->fresh()->qty)->toBe($target->qty)
        ->and($sibling->fresh()->price_satang)->toBe(100);
});

test('the importer fails an unmatched row without creating anything', function () {
    $variant = Variant::factory()->create(['price_satang' => 100]);
    $count = Variant::query()->count();

    VariantImporter::test()
        ->import(['sku' => 'NOPE-1', 'price' => '5'])
        ->assertHasRowFailure(__('admin.import.not_found'));

    VariantImporter::test()
        ->import([
            'sku' => '', 'set_code' => 'ZZZ', 'number' => '1', 'language' => 'en',
            'condition' => 'nm', 'finish' => 'normal', 'price' => '5',
        ])
        ->assertHasRowFailure(__('admin.import.not_found'));

    VariantImporter::test()
        ->import(['sku' => '', 'set_code' => 'ZZZ', 'price' => '5'])
        ->assertHasRowFailure(__('admin.import.missing_identity'));

    expect(Variant::query()->count())->toBe($count)
        ->and($variant->fresh()->price_satang)->toBe(100);
});

test('the importer fails a composite key shared by two games as ambiguous', function () {
    foreach ([Game::factory()->create(), Game::factory()->create()] as $game) {
        $set = CardSet::factory()->for($game)->create(['code' => 'DUP']);
        Variant::factory()->for(Printing::factory()->for($set, 'set')->create(['number' => '7', 'language' => Language::En]))->create();
    }

    VariantImporter::test()
        ->import([
            'sku' => '', 'set_code' => 'DUP', 'number' => '7', 'language' => 'en',
            'condition' => 'nm', 'finish' => 'normal', 'price' => '5',
        ])
        ->assertHasRowFailure(__('admin.import.ambiguous'));

    expect(Variant::query()->whereNotNull('price_satang')->where('price_satang', 500)->exists())->toBeFalse();
});

test('the importer rejects a bad price and a bad qty with a Thai reason', function () {
    $variant = Variant::factory()->create(['price_satang' => 100, 'qty' => 2]);

    VariantImporter::test()
        ->import(['sku' => $variant->sku, 'price' => '1.234', 'qty' => '1'])
        ->assertHasErrors(['price']);

    VariantImporter::test()
        ->import(['sku' => $variant->sku, 'price' => '5', 'qty' => '-3'])
        ->assertHasErrors(['qty']);

    expect($variant->fresh()->price_satang)->toBe(100)
        ->and($variant->fresh()->qty)->toBe(2);
});

test('an unmapped importer column is neither written nor validated', function () {
    $variant = Variant::factory()->create(['price_satang' => 100, 'qty' => 2]);

    // qty is unmapped but the raw row still carries a bad value under that header.
    VariantImporter::test(['sku' => 'sku', 'price' => 'price'])
        ->import(['sku' => $variant->sku, 'price' => '5', 'qty' => '-3'])
        ->assertImported();

    expect($variant->fresh()->qty)->toBe(2)->and($variant->fresh()->price_satang)->toBe(500);

    // price is unmapped: its invalid raw value neither throws nor is written.
    VariantImporter::test(['sku' => 'sku', 'qty' => 'qty'])
        ->import(['sku' => $variant->sku, 'price' => '1.234', 'qty' => '8'])
        ->assertImported();

    expect($variant->fresh()->price_satang)->toBe(500)->and($variant->fresh()->qty)->toBe(8);
});

test('an unmapped sku column forces composite key matching', function () {
    $set = CardSet::factory()->create(['code' => 'MAP1']);
    $printing = Printing::factory()->for($set, 'set')->create(['number' => '5', 'language' => Language::En]);
    $variant = Variant::factory()->for($printing)->create(['price_satang' => 100]);
    $decoy = Variant::factory()->create(['price_satang' => 100]);

    VariantImporter::test(['set_code' => 'set_code', 'number' => 'number', 'language' => 'language', 'condition' => 'condition', 'finish' => 'finish', 'price' => 'price'])
        ->import([
            'sku' => $decoy->sku, 'set_code' => 'MAP1', 'number' => '5', 'language' => 'en',
            'condition' => 'nm', 'finish' => 'normal', 'price' => '7',
        ])
        ->assertImported();

    expect($variant->fresh()->price_satang)->toBe(700)->and($decoy->fresh()->price_satang)->toBe(100);
});

test('the importer refuses a variant whose printing or card is trashed on both key paths', function () {
    $set = CardSet::factory()->create(['code' => 'TRSH']);
    $trashedPrinting = Printing::factory()->for($set, 'set')->create(['number' => '1', 'language' => Language::En]);
    $printingVariant = Variant::factory()->for($trashedPrinting)->create(['price_satang' => 100]);
    $trashedPrinting->delete();

    $card = Card::factory()->create();
    $cardPrinting = Printing::factory()->for($set, 'set')->for($card)->create(['number' => '2', 'language' => Language::En]);
    $cardVariant = Variant::factory()->for($cardPrinting)->create(['price_satang' => 100]);
    $cardPrinting->delete();
    $card->delete();
    $cardPrinting->restore();

    $composite = fn (string $number) => [
        'sku' => '', 'set_code' => 'TRSH', 'number' => $number, 'language' => 'en',
        'condition' => 'nm', 'finish' => 'normal', 'price' => '5',
    ];

    VariantImporter::test()->import(['sku' => $printingVariant->sku, 'price' => '5'])
        ->assertHasRowFailure(__('admin.import.printing_trashed'));
    VariantImporter::test()->import($composite('1'))
        ->assertHasRowFailure(__('admin.import.printing_trashed'));
    VariantImporter::test()->import(['sku' => $cardVariant->sku, 'price' => '5'])
        ->assertHasRowFailure(__('admin.import.card_trashed'));
    VariantImporter::test()->import($composite('2'))
        ->assertHasRowFailure(__('admin.import.card_trashed'));

    expect($printingVariant->fresh()->price_satang)->toBe(100)
        ->and($cardVariant->fresh()->price_satang)->toBe(100);
});

test('the variant edit page refuses a save when the variant changed elsewhere', function () {
    $variant = Variant::factory()->create(['price_satang' => 100, 'qty' => 1, 'note_th' => null]);

    $component = Livewire::test(EditVariant::class, ['record' => $variant->getKey()]);

    $variant->update(['qty' => 50]);

    $component
        ->fillForm(['note_th' => 'hello'])
        ->call('save')
        ->assertNotified(__('admin.notifications.variants_changed_elsewhere'));

    expect($variant->fresh()->qty)->toBe(50)->and($variant->fresh()->note_th)->toBeNull();
});

test('the variant edit page saves normally, twice in a row', function () {
    $variant = Variant::factory()->create(['qty' => 1]);

    Livewire::test(EditVariant::class, ['record' => $variant->getKey()])
        ->fillForm(['qty' => 5])
        ->call('save')
        ->assertHasNoFormErrors()
        ->fillForm(['qty' => 6])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($variant->fresh()->qty)->toBe(6);
});

test('the import modal offers the example csv under its Thai label', function () {
    $component = Livewire::test(ListVariants::class)->mountAction('import');

    $labels = collect($component->instance()->getMountedAction()->getModalActions())
        ->map(fn ($action) => $action->getLabel());

    expect($labels->all())->toContain('ดาวน์โหลดไฟล์ CSV ตัวอย่าง');
});
