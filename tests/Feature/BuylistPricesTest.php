<?php

use App\Actions\BulkEditBuylistPrices;
use App\Enums\BuylistPayout;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Exceptions\BuylistPriceInvalid;
use App\Filament\Imports\BuylistPriceImporter;
use App\Filament\Resources\BuylistPrices\BuylistPriceResource;
use App\Filament\Resources\BuylistPrices\Pages\CreateBuylistPrice;
use App\Filament\Resources\BuylistPrices\Pages\EditBuylistPrice;
use App\Filament\Resources\BuylistPrices\Pages\ListBuylistPrices;
use App\Jobs\ResetBuylistQuotas;
use App\Models\BuylistPrice;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the buyable scope keeps active rows with a cash price on a printing the storefront shows', function () {
    $buyable = buylistRow();
    buylistRow(['is_active' => false]);
    buylistRow(['cash_satang' => 0, 'credit_satang' => 0]);

    $trashedPrinting = buylistRow();
    $trashedPrinting->printing->delete();

    $trashedCard = buylistRow();
    $trashedCard->printing->card->delete();

    $inactiveGame = buylistRow();
    $inactiveGame->printing->set->game->update(['is_active' => false]);

    expect(BuylistPrice::query()->buyable()->pluck('id')->all())->toBe([$buyable->id]);
});

test('the printing relation tolerates a trashed printing', function () {
    $row = buylistRow();
    $row->printing->delete();

    expect($row->fresh()->printing)->not->toBeNull();
});

test('remaining quota is the weekly quota less what is committed, floored at zero, and null without a cap', function () {
    expect(buylistRow()->remainingQuota())->toBeNull()
        ->and(buylistRow(['weekly_quota' => 10, 'accepted_this_week' => 4])->remainingQuota())->toBe(6)
        ->and(buylistRow(['weekly_quota' => 10, 'accepted_this_week' => 12])->remainingQuota())->toBe(0);
});

test('the unit price follows the payout', function () {
    $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);

    expect($row->unitFor(BuylistPayout::Cash))->toBe(10000)
        ->and($row->unitFor(BuylistPayout::Credit))->toBe(11500);
});

test('credit is cash plus the bonus in whole baht', function (int $cash, int $credit) {
    expect(BuylistPrice::creditFor($cash))->toBe($credit);
})->with([
    'a round price' => [10000, 11500],
    'rounds to the baht' => [10050, 11600],
    'rounds down' => [9900, 11400],
]);

test('the reset job clears every quota count and is scheduled for Monday at midnight shop time', function () {
    $taken = buylistRow(['weekly_quota' => 10, 'accepted_this_week' => 7]);
    $other = buylistRow(['accepted_this_week' => 3]);

    (new ResetBuylistQuotas)->handle();

    expect($taken->fresh()->accepted_this_week)->toBe(0)
        ->and($other->fresh()->accepted_this_week)->toBe(0)
        ->and((new ResetBuylistQuotas)->tries)->toBe(3);

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->description, 'ResetBuylistQuotas'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * 1')
        ->and((string) $event->timezone)->toBe(config('shop.timezone'));
});

test('a printing has one price per condition and finish', function () {
    $row = buylistRow(printing: ['has_foil' => true]);

    BuylistPrice::factory()->for($row->printing, 'printing')->foil()->create();
    BuylistPrice::factory()->for($row->printing, 'printing')->create(['condition' => CardCondition::Lp]);

    expect(fn () => BuylistPrice::factory()->for($row->printing, 'printing')->create())->toThrow(QueryException::class);
});

test('both bulk actions lock the selected rows ascending and save them ascending, whatever order the table handed them in', function (string $action) {
    $rows = collect(range(1, 3))->map(fn () => buylistRow(['cash_satang' => 10000, 'credit_satang' => 10000]));
    $descending = $rows->sortByDesc('id')->values();
    $log = [];

    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = ['BEGIN', []];
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = [$query->sql, $query->bindings];
    });

    Livewire::test(ListBuylistPrices::class)
        ->callTableBulkAction($action, $descending->all(), $action === 'edit_prices' ? ['cash' => '50', 'credit' => '', 'quota' => '', 'clear_quota' => false, 'active' => 'keep'] : []);

    $begin = collect($log)->search(fn (array $entry): bool => $entry[0] === 'BEGIN');
    $updates = collect($log)->filter(fn (array $entry): bool => str_starts_with($entry[0], 'update "buylist_prices"'))->map(fn (array $entry): int => (int) last($entry[1]))->values()->all();

    expect($log[$begin + 1][0])->toStartWith('select * from "buylist_prices"')
        ->and($log[$begin + 1][0])->toContain('order by "id" asc')
        ->and($updates)->toBe($rows->pluck('id')->all());
})->with(['the bulk edit' => 'edit_prices', 'the credit bonus' => 'credit_bonus']);

test('a credit price below the cash price, or a cash price of zero, is refused by the form', function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
    $printing = buylistRow()->printing;

    Livewire::test(CreateBuylistPrice::class)
        ->fillForm(['printing_id' => $printing->id, 'condition' => 'lp', 'finish' => 'normal', 'cash_satang' => '100', 'credit_satang' => '99.99'])
        ->call('create')
        ->assertHasFormErrors(['credit_satang']);

    Livewire::test(CreateBuylistPrice::class)
        ->fillForm(['printing_id' => $printing->id, 'condition' => 'lp', 'finish' => 'normal', 'cash_satang' => '0', 'credit_satang' => ''])
        ->call('create')
        ->assertHasFormErrors(['cash_satang']);

    Livewire::test(CreateBuylistPrice::class)
        ->fillForm(['printing_id' => $printing->id, 'condition' => 'lp', 'finish' => 'normal', 'cash_satang' => '100', 'credit_satang' => '100'])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('the bulk edit refuses the whole action and names the row when credit would end below cash', function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
    $a = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    $b = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    $b->update(['credit_satang' => 10000]);

    // Raising cash to 105 leaves $a's credit (115) fine and pushes $b's credit (100) under it.
    expect(fn () => app(BulkEditBuylistPrices::class)->edit(BuylistPrice::query()->get(), 10500, null, null, false, null))
        ->toThrow(BuylistPriceInvalid::class, $b->label());

    expect($a->fresh()->cash_satang)->toBe(10000)
        ->and($b->fresh()->cash_satang)->toBe(10000);

    Livewire::test(ListBuylistPrices::class)
        ->callTableBulkAction('edit_prices', [$a, $b], ['cash' => '105', 'credit' => '', 'quota' => '', 'clear_quota' => false, 'active' => 'keep'])
        ->assertNotified(__('admin.buylist.validation.credit_below_cash').' ('.$b->label().')');

    expect($a->fresh()->cash_satang)->toBe(10000);

    expect(fn () => app(BulkEditBuylistPrices::class)->edit(BuylistPrice::query()->get(), 0, null, null, false, null))->toThrow(BuylistPriceInvalid::class);
});

test('the importer reports a row that would leave credit below cash as a failed row', function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
    $set = CardSet::factory()->create(['code' => 'OP05']);
    Printing::factory()->for($set, 'set')->for(Card::factory())->create(['number' => '119', 'language' => Language::En]);
    $row = ['set_code' => 'OP05', 'number' => '119', 'language' => 'en', 'condition' => 'nm', 'finish' => 'normal', 'cash_baht' => '100', 'credit_baht' => '99', 'weekly_quota' => '', 'is_active' => ''];

    BuylistPriceImporter::test()->import($row)->assertHasRowFailure(__('admin.buylist.validation.credit_below_cash'));

    expect(BuylistPrice::query()->count())->toBe(0);

    BuylistPriceImporter::test()->import(array_merge($row, ['credit_baht' => '100']))->assertImported();
});

describe('admin', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
    });

    test('the buy prices list renders for staff in the buylist group and is forbidden for a customer', function () {
        $row = buylistRow(printing: ['number' => '119']);

        $this->get(BuylistPriceResource::getUrl('index'))->assertOk()->assertSee($row->printing->card->name_en);
        $this->get(BuylistPriceResource::getUrl('create'))->assertOk();
        $this->get(BuylistPriceResource::getUrl('edit', ['record' => $row]))->assertOk();

        expect(BuylistPriceResource::getNavigationGroup())->toBe(__('admin.navigation.buylist'));

        $this->actingAs(User::factory()->create())
            ->get(BuylistPriceResource::getUrl('index'))
            ->assertForbidden();
    });

    test('a blank credit price on create is the cash price plus the bonus in whole baht', function () {
        $printing = buylistRow()->printing;

        Livewire::test(CreateBuylistPrice::class)
            ->fillForm(['printing_id' => $printing->id, 'condition' => 'lp', 'finish' => 'normal', 'cash_satang' => '100.50', 'credit_satang' => ''])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = BuylistPrice::query()->where('condition', 'lp')->firstOrFail();

        expect($created->cash_satang)->toBe(10050)
            ->and($created->credit_satang)->toBe(11600);
    });

    test('the same printing, condition and finish cannot be priced twice', function () {
        $row = buylistRow(['condition' => CardCondition::Nm]);

        Livewire::test(CreateBuylistPrice::class)
            ->fillForm(['printing_id' => $row->printing_id, 'condition' => 'nm', 'finish' => 'normal', 'cash_satang' => '10'])
            ->call('create')
            ->assertHasFormErrors(['finish']);

        expect(BuylistPrice::query()->count())->toBe(1);
    });

    test('foil is only for a printing that has a foil', function () {
        $plain = buylistRow()->printing;
        $foiled = buylistRow(printing: ['has_foil' => true])->printing;

        Livewire::test(CreateBuylistPrice::class)
            ->fillForm(['printing_id' => $plain->id, 'condition' => 'nm', 'finish' => 'foil', 'cash_satang' => '10'])
            ->call('create')
            ->assertHasFormErrors(['finish']);

        Livewire::test(CreateBuylistPrice::class)
            ->fillForm(['printing_id' => $foiled->id, 'condition' => 'nm', 'finish' => 'foil', 'cash_satang' => '10'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(BuylistPrice::query()->where('finish', 'foil')->count())->toBe(1);
    });

    test('printing, condition and finish are read-only on edit and editable on create', function () {
        $row = buylistRow(printing: ['has_foil' => true]);

        $edit = Livewire::test(EditBuylistPrice::class, ['record' => $row->getKey()]);
        $create = Livewire::test(CreateBuylistPrice::class);

        foreach (['printing_id', 'condition', 'finish'] as $field) {
            $edit->assertFormFieldIsDisabled($field);
            $create->assertFormFieldIsEnabled($field);
        }
    });

    test('a row re-pointed elsewhere makes the open edit page stale', function () {
        $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
        $other = Printing::factory()->for($row->printing->card)->for($row->printing->set, 'set')->create();

        $page = Livewire::test(EditBuylistPrice::class, ['record' => $row->getKey()]);
        $row->update(['printing_id' => $other->id]);

        $page->fillForm(['cash_satang' => '110'])->call('save');

        expect($row->fresh()->cash_satang)->toBe(10000);

        // The same save on a page that was not stale goes through, so the refusal above is the fingerprint's.
        Livewire::test(EditBuylistPrice::class, ['record' => $row->getKey()])->fillForm(['cash_satang' => '110'])->call('save')->assertHasNoFormErrors();

        expect($row->fresh()->cash_satang)->toBe(11000);
    });

    test('the edit page refuses a save made after the row changed elsewhere', function () {
        $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 30000]);

        $page = Livewire::test(EditBuylistPrice::class, ['record' => $row->getKey()]);
        $row->update(['cash_satang' => 20000]);

        $page->fillForm(['cash_satang' => '300'])->call('save');

        expect($row->fresh()->cash_satang)->toBe(20000);
    });

    test('the bulk edit sets the fields it is given and leaves the others alone', function () {
        $a = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500, 'weekly_quota' => 5]);
        $b = buylistRow(['cash_satang' => 20000, 'credit_satang' => 23000]);

        Livewire::test(ListBuylistPrices::class)
            ->callTableBulkAction('edit_prices', [$a, $b], ['cash' => '50', 'credit' => '', 'quota' => '', 'clear_quota' => false, 'active' => 'off'])
            ->assertHasNoTableBulkActionErrors();

        expect($a->fresh()->cash_satang)->toBe(5000)
            ->and($a->fresh()->credit_satang)->toBe(11500)
            ->and($a->fresh()->weekly_quota)->toBe(5)
            ->and($a->fresh()->is_active)->toBeFalse()
            ->and($b->fresh()->cash_satang)->toBe(5000);

        Livewire::test(ListBuylistPrices::class)
            ->callTableBulkAction('edit_prices', [$a], ['cash' => '', 'credit' => '', 'quota' => '9', 'clear_quota' => true, 'active' => 'on']);

        expect($a->fresh()->weekly_quota)->toBeNull()
            ->and($a->fresh()->is_active)->toBeTrue();
    });

    test('the credit bonus action sets credit to cash plus the bonus in whole baht', function () {
        $a = buylistRow(['cash_satang' => 10000, 'credit_satang' => 1]);
        $b = buylistRow(['cash_satang' => 10050, 'credit_satang' => 1]);

        Livewire::test(ListBuylistPrices::class)
            ->callTableBulkAction('credit_bonus', [$a, $b]);

        expect($a->fresh()->credit_satang)->toBe(11500)
            ->and($b->fresh()->credit_satang)->toBe(11600);

        config(['buylist.credit_bonus_bp' => 2000]);

        expect(app(BulkEditBuylistPrices::class)->setCreditFromCash(BuylistPrice::query()->whereKey($a->id)->get()))->toBe(1)
            ->and($a->fresh()->credit_satang)->toBe(12000);
    });

    test('the importer creates a row, fills the credit from the bonus and updates an existing row by its key', function () {
        $set = CardSet::factory()->create(['code' => 'OP05']);
        $printing = Printing::factory()->for($set, 'set')->for(Card::factory())->create(['number' => '119', 'language' => Language::Ja, 'has_foil' => true]);
        $existing = BuylistPrice::factory()->for($printing, 'printing')->create(['condition' => CardCondition::Lp, 'cash_satang' => 10000, 'credit_satang' => 11500, 'weekly_quota' => 4]);

        BuylistPriceImporter::test()
            ->import(['set_code' => 'OP05', 'number' => '119', 'language' => 'JA', 'condition' => 'NM', 'finish' => '', 'cash_baht' => '100.50', 'credit_baht' => '', 'weekly_quota' => '8', 'is_active' => ''])
            ->assertImported();

        $created = BuylistPrice::query()->where('condition', 'nm')->firstOrFail();

        expect($created->finish)->toBe(Finish::Normal)
            ->and($created->cash_satang)->toBe(10050)
            ->and($created->credit_satang)->toBe(11600)
            ->and($created->weekly_quota)->toBe(8)
            ->and($created->is_active)->toBeTrue();

        BuylistPriceImporter::test()
            ->import(['set_code' => 'OP05', 'number' => '119', 'language' => 'ja', 'condition' => 'lp', 'finish' => 'normal', 'cash_baht' => '110', 'credit_baht' => '', 'weekly_quota' => '', 'is_active' => '0'])
            ->assertImported();

        expect(BuylistPrice::query()->count())->toBe(2)
            ->and($existing->fresh()->cash_satang)->toBe(11000)
            ->and($existing->fresh()->credit_satang)->toBe(11500)
            ->and($existing->fresh()->weekly_quota)->toBe(4)
            ->and($existing->fresh()->is_active)->toBeFalse();
    });

    test('the importer fails a row that matches no printing, a trashed one, a foil of a plain printing and a new row without cash', function () {
        $set = CardSet::factory()->create(['code' => 'OP05']);
        $printing = Printing::factory()->for($set, 'set')->for(Card::factory())->create(['number' => '119', 'language' => Language::En]);
        $row = fn (array $over = []): array => array_merge(['set_code' => 'OP05', 'number' => '119', 'language' => 'en', 'condition' => 'nm', 'finish' => 'normal', 'cash_baht' => '10', 'credit_baht' => '', 'weekly_quota' => '', 'is_active' => ''], $over);

        BuylistPriceImporter::test()->import($row(['number' => '999']))->assertHasRowFailure(__('admin.buylist.import.not_found'));
        BuylistPriceImporter::test()->import($row(['finish' => 'foil']))->assertHasRowFailure(__('admin.buylist.validation.foil'));
        BuylistPriceImporter::test()->import($row(['cash_baht' => '']))->assertHasRowFailure(__('admin.buylist.import.cash_required'));
        BuylistPriceImporter::test()->import($row(['cash_baht' => '0']))->assertHasRowFailure(__('admin.buylist.validation.cash_positive'));
        BuylistPriceImporter::test()->import($row(['cash_baht' => 'abc']))->assertHasErrors(['cash_baht']);
        BuylistPriceImporter::test()->import($row(['set_code' => '']))->assertHasRowFailure(__('admin.buylist.import.missing_identity'));

        $printing->delete();
        BuylistPriceImporter::test()->import($row())->assertHasRowFailure(__('admin.buylist.import.printing_trashed'));

        expect(BuylistPrice::query()->count())->toBe(0);
    });

    test('the importer fails a code shared by two games as ambiguous', function () {
        foreach ([Game::factory()->create(), Game::factory()->create()] as $game) {
            Printing::factory()
                ->for(CardSet::factory()->for($game, 'game')->create(['code' => 'OP05']), 'set')
                ->for(Card::factory()->for($game, 'game'))
                ->create(['number' => '119', 'language' => Language::En]);
        }

        BuylistPriceImporter::test()
            ->import(['set_code' => 'OP05', 'number' => '119', 'language' => 'en', 'condition' => 'nm', 'finish' => 'normal', 'cash_baht' => '10'])
            ->assertHasRowFailure(__('admin.buylist.import.ambiguous'));

        expect(BuylistPrice::query()->count())->toBe(0);
    });

    test('an unmapped importer column is neither written nor validated', function () {
        $set = CardSet::factory()->create(['code' => 'OP05']);
        $printing = Printing::factory()->for($set, 'set')->for(Card::factory())->create(['number' => '119', 'language' => Language::En]);
        $existing = BuylistPrice::factory()->for($printing, 'printing')->create(['cash_satang' => 10000, 'credit_satang' => 11500, 'weekly_quota' => 4]);
        $mapped = ['set_code' => 'set_code', 'number' => 'number', 'language' => 'language', 'condition' => 'condition', 'cash_baht' => 'cash_baht'];

        // The raw row carries garbage for the three columns that are not mapped: it must neither fail nor be written.
        BuylistPriceImporter::test($mapped)
            ->import(['set_code' => 'OP05', 'number' => '119', 'language' => 'en', 'condition' => 'nm', 'cash_baht' => '77', 'credit_baht' => 'not money', 'weekly_quota' => 'many', 'is_active' => 'maybe'])
            ->assertImported();

        $fresh = $existing->fresh();

        expect($fresh->cash_satang)->toBe(7700)
            ->and($fresh->credit_satang)->toBe(11500)
            ->and($fresh->weekly_quota)->toBe(4)
            ->and($fresh->is_active)->toBeTrue();
    });
});
