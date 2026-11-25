<?php

require_once __DIR__.'/../Support/graded.php';

use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Exceptions\GradedNotListable;
use App\Filament\Resources\GradedItems\GradedItemResource;
use App\Filament\Resources\GradedItems\Pages\CreateGradedItem;
use App\Filament\Resources\GradedItems\Pages\EditGradedItem;
use App\Filament\Resources\GradedItems\Pages\ListGradedItems;
use App\Filament\Resources\Variants\VariantResource;
use App\Models\GradedItem;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
});

test('the graded items list renders for staff and is forbidden for a customer', function () {
    gradedItem([], gradedPrinting('Pikachu'));

    $this->get(GradedItemResource::getUrl('index'))->assertOk()->assertSee('Pikachu')->assertSee('PSA 10');
    $this->get(GradedItemResource::getUrl('create'))->assertOk();

    $this->actingAs(User::factory()->create())->get(GradedItemResource::getUrl('index'))->assertForbidden();
    $this->get(GradedItemResource::getUrl('create'))->assertForbidden();
});

test('the resource sits in the catalogue group after variants with Thai labels', function () {
    expect(GradedItemResource::getNavigationGroup())->toBe('แคตตาล็อก')
        ->and(GradedItemResource::getPluralModelLabel())->toBe('การ์ดเกรด')
        ->and(GradedItemResource::getNavigationSort())->toBeGreaterThan(VariantResource::getNavigationSort());
});

test('creating an item with its three photos stores them on the public disk and lists it', function () {
    Storage::fake('public');
    $printing = gradedPrinting();

    Livewire::test(CreateGradedItem::class)
        ->fillForm([
            'printing_id' => $printing->id,
            'grader' => Grader::Bgs->value,
            'grade' => 9.5,
            'price_satang' => '8000.50',
            'cert_number' => '12344821',
            'front_path' => UploadedFile::fake()->image('front.jpg'),
            'back_path' => UploadedFile::fake()->image('back.jpg'),
            'label_path' => UploadedFile::fake()->image('label.jpg'),
            'status' => GradedStatus::Listed->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = GradedItem::query()->firstOrFail();

    expect($item->status)->toBe(GradedStatus::Listed)
        ->and($item->price_satang)->toBe(800050)
        ->and($item->grader)->toBe(Grader::Bgs)
        ->and((string) $item->grade)->toBe('9.5')
        ->and($item->subgrades)->toBeNull();

    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        expect($item->{$column})->toStartWith('graded-photos/');
        Storage::disk('public')->assertExists($item->{$column});
    }
});

test('listing without the photos is a validation error on the status, not a crash', function () {
    Livewire::test(CreateGradedItem::class)
        ->fillForm([
            'printing_id' => gradedPrinting()->id,
            'grader' => Grader::Psa->value,
            'grade' => 10,
            'price_satang' => '100',
            'status' => GradedStatus::Listed->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['status']);

    expect(GradedItem::query()->count())->toBe(0);
});

test('an item can be saved withdrawn without photos', function () {
    Livewire::test(CreateGradedItem::class)
        ->fillForm([
            'printing_id' => gradedPrinting()->id,
            'grader' => Grader::Cgc->value,
            'grade' => 10,
            'price_satang' => '100',
            'status' => GradedStatus::Withdrawn->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(GradedItem::query()->firstOrFail()->status)->toBe(GradedStatus::Withdrawn);
});

test('the edit page saves the price and the subgrades, and keeps only the subgrades that are filled', function () {
    Storage::fake('public');
    $item = gradedItem(['price_satang' => 100000]);

    // The upload field only keeps a path whose file is on the disk.
    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($item->{$column}, 'x');
    }

    Livewire::test(EditGradedItem::class, ['record' => $item->getKey()])
        ->assertSet('data.price_satang', '1000')
        ->fillForm(['price_satang' => '1234.50', 'subgrades' => ['centering' => 9.5, 'corners' => '', 'edges' => '', 'surface' => '']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->price_satang)->toBe(123450)
        ->and($item->fresh()->subgrades)->toBe(['centering' => 9.5]);
});

test('replacing a photo deletes the old file once saved', function () {
    Storage::fake('public');
    Storage::disk('public')->put('graded-photos/old-front.jpg', 'x');
    $item = gradedItem(['front_path' => 'graded-photos/old-front.jpg']);

    $item->update(['front_path' => 'graded-photos/new-front.jpg']);

    Storage::disk('public')->assertMissing('graded-photos/old-front.jpg');
});

test('deleting an item deletes its photo files', function () {
    Storage::fake('public');
    $item = gradedItem();
    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($item->{$column}, 'x');
    }

    $item->delete();

    foreach (['front', 'back', 'label'] as $name) {
        Storage::disk('public')->assertMissing("graded-photos/{$name}.jpg");
    }
});

test('a stale edit page is refused after the item changed under it', function () {
    Storage::fake('public');
    $item = gradedItem(['price_satang' => 100000]);

    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($item->{$column}, 'x');
    }

    $page = Livewire::test(EditGradedItem::class, ['record' => $item->getKey()]);

    $item->update(['price_satang' => 250000]);

    $page->fillForm(['price_satang' => '1']);
    $page->call('save')->assertHasNoFormErrors()->assertNotified(__('admin.notifications.graded_changed_elsewhere'));

    expect($item->fresh()->price_satang)->toBe(250000);
});

test('the withdraw bulk action withdraws listed items and skips sold and reserved ones', function () {
    $listed = gradedItem();
    $reserved = gradedItem(['status' => GradedStatus::Reserved]);
    $sold = gradedItem(['status' => GradedStatus::Sold]);
    $untouched = gradedItem();

    Livewire::test(ListGradedItems::class)
        ->callTableBulkAction('withdraw', [$listed, $reserved, $sold]);

    expect($listed->fresh()->status)->toBe(GradedStatus::Withdrawn)
        ->and($reserved->fresh()->status)->toBe(GradedStatus::Reserved)
        ->and($sold->fresh()->status)->toBe(GradedStatus::Sold)
        ->and($untouched->fresh()->status)->toBe(GradedStatus::Listed);
});

test('the list filters by status and grader', function () {
    $psa = gradedItem(['grader' => Grader::Psa]);
    $bgs = gradedItem(['grader' => Grader::Bgs, 'status' => GradedStatus::Sold]);

    Livewire::test(ListGradedItems::class)
        ->filterTable('grader', Grader::Bgs->value)
        ->assertCanSeeTableRecords([$bgs])
        ->assertCanNotSeeTableRecords([$psa])
        ->removeTableFilters()
        ->filterTable('status', GradedStatus::Listed->value)
        ->assertCanSeeTableRecords([$psa])
        ->assertCanNotSeeTableRecords([$bgs]);
});

test('the status select offers only listed and withdrawn, and is locked on a reserved or sold item', function () {
    Storage::fake('public');
    $listed = gradedItem();
    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($listed->{$column}, 'x');
    }

    Livewire::test(EditGradedItem::class, ['record' => $listed->getKey()])
        ->assertFormFieldEnabled('status')
        ->assertFormFieldExists('status', fn (Select $field): bool => $field->getOptions() === [
            GradedStatus::Listed->value => GradedStatus::Listed->label(),
            GradedStatus::Withdrawn->value => GradedStatus::Withdrawn->label(),
        ]);

    foreach ([GradedStatus::Reserved, GradedStatus::Sold] as $state) {
        $item = gradedItem(['status' => $state]);

        Livewire::test(EditGradedItem::class, ['record' => $item->getKey()])
            ->assertFormFieldDisabled('status')
            ->assertFormFieldExists('status', fn (Select $field): bool => $field->getOptions() === [$state->value => $state->label()]);
    }
});

test('saving a reserved item leaves its status alone', function () {
    Storage::fake('public');
    $item = gradedItem(['status' => GradedStatus::Reserved, 'price_satang' => 100000]);
    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($item->{$column}, 'x');
    }

    Livewire::test(EditGradedItem::class, ['record' => $item->getKey()])
        ->fillForm(['price_satang' => '2000'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->status)->toBe(GradedStatus::Reserved)
        ->and($item->fresh()->price_satang)->toBe(200000);
});

test('listing from the form needs the certificate number as well as the photos', function () {
    Storage::fake('public');

    Livewire::test(CreateGradedItem::class)
        ->fillForm([
            'printing_id' => gradedPrinting()->id,
            'grader' => Grader::Psa->value,
            'grade' => 10,
            'price_satang' => '100',
            'front_path' => UploadedFile::fake()->image('front.jpg'),
            'back_path' => UploadedFile::fake()->image('back.jpg'),
            'label_path' => UploadedFile::fake()->image('label.jpg'),
            'status' => GradedStatus::Listed->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['status']);

    expect(GradedItem::query()->count())->toBe(0);
});

test('clearing the certificate of a reserved item is a form error, not a crash', function () {
    Storage::fake('public');
    $item = gradedItem(['status' => GradedStatus::Reserved]);
    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($item->{$column}, 'x');
    }

    Livewire::test(EditGradedItem::class, ['record' => $item->getKey()])
        ->fillForm(['cert_number' => ''])
        ->call('save')
        ->assertHasFormErrors(['cert_number' => 'required']);

    expect($item->fresh()->cert_number)->not->toBeNull()
        ->and($item->fresh()->status)->toBe(GradedStatus::Reserved);
});

test('clearing a photo of a reserved item is a form error on that photo', function (string $column) {
    Storage::fake('public');
    $item = gradedItem(['status' => GradedStatus::Reserved]);
    foreach (GradedItem::PHOTO_COLUMNS as $name) {
        Storage::disk('public')->put($item->{$name}, 'x');
    }

    Livewire::test(EditGradedItem::class, ['record' => $item->getKey()])
        ->fillForm([$column => null])
        ->call('save')
        ->assertHasFormErrors([$column => 'required']);

    expect($item->fresh()->{$column})->not->toBeNull();
})->with(['front_path', 'back_path', 'label_path']);

test('a refusal the form could not see is shown as a notification, and the page stays', function () {
    Storage::fake('public');
    $item = gradedItem();
    foreach (GradedItem::PHOTO_COLUMNS as $column) {
        Storage::disk('public')->put($item->{$column}, 'x');
    }

    // Armed only inside this test: a listener cannot be removed without dropping the model's own hooks.
    GradedItem::saving(function (): void {
        if ($GLOBALS['graded_backstop'] ?? false) {
            throw new GradedNotListable;
        }
    });
    $GLOBALS['graded_backstop'] = true;

    try {
        Livewire::test(EditGradedItem::class, ['record' => $item->getKey()])
            ->fillForm(['note_th' => 'ใหม่'])
            ->call('save')
            ->assertNotified(__('admin.notifications.graded_not_listable'));
    } finally {
        $GLOBALS['graded_backstop'] = false;
    }

    expect($item->fresh()->note_th)->toBeNull();
});
