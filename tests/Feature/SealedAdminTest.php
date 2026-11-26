<?php

require_once __DIR__.'/../Support/sealed.php';

use App\Enums\SealedKind;
use App\Filament\Resources\SealedProducts\Pages\CreateSealedProduct;
use App\Filament\Resources\SealedProducts\Pages\EditSealedProduct;
use App\Filament\Resources\SealedProducts\Pages\ListSealedProducts;
use App\Filament\Resources\SealedProducts\SealedProductResource;
use App\Filament\Resources\Variants\VariantResource;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\SealedProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
});

test('the list renders for staff and the pages are forbidden for a customer', function () {
    $product = sealedProduct(['name_en' => 'OP-05 Booster Box', 'sku' => 'BX-OP05']);

    $this->get(SealedProductResource::getUrl('index'))->assertOk()->assertSee('OP-05 Booster Box')->assertSee('BX-OP05');
    $this->get(SealedProductResource::getUrl('create'))->assertOk();
    $this->get(SealedProductResource::getUrl('edit', ['record' => $product]))->assertOk();

    $this->actingAs(User::factory()->create());

    $this->get(SealedProductResource::getUrl('index'))->assertForbidden();
    $this->get(SealedProductResource::getUrl('create'))->assertForbidden();
    $this->get(SealedProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
});

test('the resource sits in the catalogue group after the variants with Thai labels', function () {
    expect(SealedProductResource::getNavigationGroup())->toBe('แคตตาล็อก')
        ->and(SealedProductResource::getPluralModelLabel())->toBe('สินค้ากล่อง')
        ->and(SealedProductResource::getNavigationSort())->toBeGreaterThan(VariantResource::getNavigationSort());
});

test('creating a product stores its image, converts the price to satang and generates the slug', function () {
    Storage::fake('public');
    $game = Game::factory()->create();
    $set = CardSet::factory()->for($game)->create();

    Livewire::test(CreateSealedProduct::class)
        ->fillForm([
            'kind' => SealedKind::BoosterBox->value,
            'game_id' => $game->id,
            'set_id' => $set->id,
            'name_en' => 'Awakening Booster Box',
            'sku' => 'BX-AWK',
            'price_satang' => '2800.50',
            'qty' => 12,
            'image_path' => UploadedFile::fake()->image('box.jpg'),
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = SealedProduct::query()->firstOrFail();

    expect($product->price_satang)->toBe(280050)
        ->and($product->qty)->toBe(12)
        ->and($product->slug)->toBe('awakening-booster-box')
        ->and($product->kind)->toBe(SealedKind::BoosterBox)
        ->and($product->is_preorder)->toBeFalse()
        ->and($product->image_path)->toStartWith('sealed-photos/');

    Storage::disk('public')->assertExists($product->image_path);
});

test('the sku must be unique, trashed products included', function () {
    $taken = sealedProduct(['sku' => 'BX-TAKEN']);
    $taken->delete();
    $game = Game::factory()->create();

    Livewire::test(CreateSealedProduct::class)
        ->fillForm(['kind' => SealedKind::BoosterBox->value, 'game_id' => $game->id, 'name_en' => 'Another', 'sku' => 'BX-TAKEN', 'qty' => 1])
        ->call('create')
        ->assertHasFormErrors(['sku']);

    expect(SealedProduct::withTrashed()->count())->toBe(1);
});

test('the slug stays unique when two products share a name, trashed ones included', function () {
    $first = sealedProduct(['name_en' => 'Twin Box']);
    $first->delete();

    expect(sealedProduct(['name_en' => 'Twin Box'])->slug)->toBe('twin-box-2')
        ->and(sealedProduct(['name_en' => 'Twin Box'])->slug)->toBe('twin-box-3');
});

test('a game is required except for an accessory', function () {
    Livewire::test(CreateSealedProduct::class)
        ->fillForm(['kind' => SealedKind::BoosterPack->value, 'name_en' => 'Pack', 'sku' => 'PK-1', 'qty' => 1])
        ->call('create')
        ->assertHasFormErrors(['game_id']);

    Livewire::test(CreateSealedProduct::class)
        ->fillForm(['kind' => SealedKind::Accessory->value, 'name_en' => 'Sleeves', 'sku' => 'SL-1', 'qty' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SealedProduct::query()->where('sku', 'SL-1')->firstOrFail()->game_id)->toBeNull();
});

test('the set must belong to the chosen game', function () {
    $game = Game::factory()->create();
    $otherSet = CardSet::factory()->for(Game::factory())->create();

    Livewire::test(CreateSealedProduct::class)
        ->fillForm(['kind' => SealedKind::BoosterBox->value, 'game_id' => $game->id, 'set_id' => $otherSet->id, 'name_en' => 'Box', 'sku' => 'BX-2', 'qty' => 1])
        ->call('create')
        ->assertHasFormErrors(['set_id']);

    expect(SealedProduct::query()->count())->toBe(0);
});

test('the edit page saves the price and the quantity and shows the price in baht', function () {
    $product = sealedProductInSet(['price_satang' => 100000, 'qty' => 4]);

    Livewire::test(EditSealedProduct::class, ['record' => $product->getKey()])
        ->assertSet('data.price_satang', '1000')
        ->fillForm(['price_satang' => '1234.50', 'qty' => 9])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->price_satang)->toBe(123450)->and($product->fresh()->qty)->toBe(9);
});

test('a stale edit page is refused after an order or another screen changed the stock under it', function () {
    $product = sealedProductInSet(['price_satang' => 100000, 'qty' => 4]);
    $page = Livewire::test(EditSealedProduct::class, ['record' => $product->getKey()]);

    $product->update(['qty' => 2]);

    $page->fillForm(['qty' => 50]);
    $page->call('save')->assertHasNoFormErrors()->assertNotified(__('admin.notifications.sealed_changed_elsewhere'));

    expect($product->fresh()->qty)->toBe(2);
});

test('replacing the image deletes the old file, and a soft delete keeps it', function () {
    Storage::fake('public');
    Storage::disk('public')->put('sealed-photos/old.jpg', 'x');
    $product = sealedProduct(['image_path' => 'sealed-photos/old.jpg']);

    $product->update(['image_path' => 'sealed-photos/new.jpg']);
    Storage::disk('public')->assertMissing('sealed-photos/old.jpg');

    Storage::disk('public')->put('sealed-photos/new.jpg', 'x');
    $product->delete();
    Storage::disk('public')->assertExists('sealed-photos/new.jpg');

    $product->forceDelete();
    Storage::disk('public')->assertMissing('sealed-photos/new.jpg');
});

test('deleting from the table soft deletes, hides the product from the storefront, and a restore brings it back', function () {
    $product = sealedProduct(['name_en' => 'Hideable Box']);

    Livewire::test(ListSealedProducts::class)
        ->callTableAction('delete', $product)
        ->assertNotified();

    expect(SealedProduct::query()->count())->toBe(0)->and(SealedProduct::onlyTrashed()->count())->toBe(1);

    $this->get(route('sealed.show', $product))->assertNotFound();

    Livewire::test(ListSealedProducts::class)
        ->assertCanNotSeeTableRecords([$product])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$product])
        ->callTableAction('restore', $product);

    expect(SealedProduct::query()->count())->toBe(1);
    $this->get(route('sealed.show', $product))->assertOk();
});

test('a trashed product opens on the edit page and can be restored there', function () {
    $product = sealedProduct();
    $product->delete();

    $this->get(SealedProductResource::getUrl('edit', ['record' => $product]))->assertOk();

    Livewire::test(EditSealedProduct::class, ['record' => $product->getKey()])->callAction('restore');

    expect($product->fresh()->trashed())->toBeFalse();
});

test('the list filters by kind and by active', function () {
    $box = sealedProduct(['kind' => SealedKind::BoosterBox]);
    $pack = sealedProduct(['kind' => SealedKind::BoosterPack, 'is_active' => false]);

    Livewire::test(ListSealedProducts::class)
        ->filterTable('kind', SealedKind::BoosterBox->value)
        ->assertCanSeeTableRecords([$box])
        ->assertCanNotSeeTableRecords([$pack])
        ->resetTableFilters()
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$pack])
        ->assertCanNotSeeTableRecords([$box]);
});
