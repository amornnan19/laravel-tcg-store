<?php

use App\Actions\RecordStoreCredit;
use App\Enums\StoreCreditReason;
use App\Exceptions\InsufficientStoreCredit;
use App\Filament\Resources\StoreCreditEntries\Pages\ListStoreCreditEntries;
use App\Filament\Resources\StoreCreditEntries\StoreCreditEntryResource;
use App\Models\Order;
use App\Models\StoreCreditEntry;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function creditStaff(): User
{
    return User::factory()->staff()->withAppAuthentication()->create();
}

function creditCustomer(int $balanceSatang = 0): User
{
    $user = User::factory()->create();

    if ($balanceSatang > 0) {
        app(RecordStoreCredit::class)->handle($user, $balanceSatang, StoreCreditReason::Adjustment, note: 'ตั้งต้น');
    }

    return $user->refresh();
}

test('an entry and the cached balance are written together', function () {
    $user = creditCustomer(10000);
    $staff = creditStaff();
    $order = Order::factory()->create();

    $entry = app(RecordStoreCredit::class)->handle($user, -4000, StoreCreditReason::Order, $order, staff: $staff);

    expect($entry->delta_satang)->toBe(-4000)
        ->and($entry->balance_after_satang)->toBe(6000)
        ->and($entry->source_type)->toBe('order')
        ->and($entry->source_id)->toBe($order->id)
        ->and($entry->created_by)->toBe($staff->id)
        ->and($entry->created_at)->not->toBeNull()
        ->and($user->store_credit_satang)->toBe(6000)
        ->and($user->refresh()->store_credit_satang)->toBe(6000)
        ->and($user->storeCreditEntries()->sum('delta_satang'))->toBe(6000);
});

test('a change that would take the balance below zero is refused and writes nothing', function () {
    $user = creditCustomer(5000);

    expect(fn () => app(RecordStoreCredit::class)->handle($user, -5001, StoreCreditReason::Order))
        ->toThrow(InsufficientStoreCredit::class);

    expect($user->refresh()->store_credit_satang)->toBe(5000)
        ->and($user->storeCreditEntries()->count())->toBe(1);

    app(RecordStoreCredit::class)->handle($user, -5000, StoreCreditReason::Order);

    expect($user->refresh()->store_credit_satang)->toBe(0);
});

test('a zero delta and an adjustment without a note are refused', function () {
    $user = creditCustomer(5000);

    expect(fn () => app(RecordStoreCredit::class)->handle($user, 0, StoreCreditReason::Refund))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(RecordStoreCredit::class)->handle($user, 100, StoreCreditReason::Adjustment))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(RecordStoreCredit::class)->handle($user, 100, StoreCreditReason::Adjustment, note: '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($user->refresh()->store_credit_satang)->toBe(5000)
        ->and($user->storeCreditEntries()->count())->toBe(1);
});

test('a ledger entry cannot be updated or deleted', function () {
    $entry = StoreCreditEntry::factory()->create();

    expect(fn () => $entry->update(['note' => 'แก้']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(StoreCreditEntry::query()->count())->toBe(1);
});

test('the user row is the first thing the transaction locks, before the entry is written', function () {
    $user = creditCustomer(5000);
    $log = [];

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(RecordStoreCredit::class)->handle($user, -1000, StoreCreditReason::Order);

    $begin = array_search('BEGIN', $log, true);
    $insert = collect($log)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "store_credit_entries"'));

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "users" where "users"."id" = ?')
        ->and($insert)->toBeGreaterThan($begin + 1);
});

test('the account page shows the balance, the latest entries with links to their orders, and the empty state', function () {
    $user = creditCustomer();

    $this->actingAs($user)->get(route('account'))
        ->assertOk()
        ->assertSee(__('storefront.account.credit.title'))
        ->assertSee(__('storefront.account.credit.note'))
        ->assertSee(__('storefront.account.credit.empty'))
        ->assertSee(__('storefront.account.credit.empty_hint'));

    $order = Order::factory()->for($user)->create();
    app(RecordStoreCredit::class)->handle($user, 20000, StoreCreditReason::Adjustment, note: 'เครดิตทดลอง');
    app(RecordStoreCredit::class)->handle($user, -7500, StoreCreditReason::Order, $order);

    $this->actingAs($user->refresh())->get(route('account'))
        ->assertOk()
        ->assertDontSee(__('storefront.account.credit.empty'))
        ->assertSee('฿ 125')
        ->assertSee('+฿ 200')
        ->assertSee('−฿ 75')
        ->assertSee(StoreCreditReason::Order->label())
        ->assertSee('เครดิตทดลอง')
        ->assertSee('href="'.route('order.show', $order->number).'"', false);
});

test('the account page lists at most the latest twenty entries', function () {
    $user = creditCustomer();
    StoreCreditEntry::factory()->count(21)->for($user)->sequence(fn ($sequence) => ['note' => 'รายการ'.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();

    $this->actingAs($user)->get(route('account'))
        ->assertSee('รายการ20')
        ->assertSee('รายการ01')
        ->assertDontSee('รายการ00');
});

test('staff see the ledger and a customer cannot reach it', function () {
    $entry = StoreCreditEntry::factory()->create();

    $this->actingAs(creditStaff());
    Livewire::test(ListStoreCreditEntries::class)->assertOk()->assertCanSeeTableRecords([$entry])->assertActionVisible('adjust');
    $this->get(StoreCreditEntryResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->create())->get(StoreCreditEntryResource::getUrl('index'))->assertForbidden();
});

test('the adjustment action adds and removes credit for a customer, with the staff member and the note', function () {
    $staff = creditStaff();
    $customer = creditCustomer(10000);
    $this->actingAs($staff);

    Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'add', 'amount_satang' => '50.50', 'note' => 'ชดเชยของเสีย'])
        ->assertHasNoActionErrors()
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'subtract', 'amount_satang' => '20', 'note' => 'แก้ยอด'])
        ->assertHasNoActionErrors();

    $entries = $customer->storeCreditEntries()->orderBy('id')->get();

    expect($customer->refresh()->store_credit_satang)->toBe(10000 + 5050 - 2000)
        ->and($entries->pluck('delta_satang')->all())->toBe([10000, 5050, -2000])
        ->and($entries[1]->reason)->toBe(StoreCreditReason::Adjustment)
        ->and($entries[1]->note)->toBe('ชดเชยของเสีย')
        ->and($entries[1]->created_by)->toBe($staff->id);
});

test('the adjustment action refuses an amount above one million baht and accepts exactly one million', function () {
    $this->actingAs(creditStaff());
    $customer = creditCustomer();

    Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'add', 'amount_satang' => '1000000.01', 'note' => 'พิมพ์ผิด'])
        ->assertHasActionErrors(['amount_satang']);

    expect($customer->refresh()->store_credit_satang)->toBe(0);

    Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'add', 'amount_satang' => '1000000', 'note' => 'เต็มวงเงิน'])
        ->assertHasNoActionErrors();

    expect($customer->refresh()->store_credit_satang)->toBe(100_000_000);
});

test('the adjustment action refuses to overdraw, a zero amount, a missing note and a staff account', function () {
    $staff = creditStaff();
    $customer = creditCustomer(1000);
    $this->actingAs($staff);

    Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'subtract', 'amount_satang' => '10.01', 'note' => 'ลดเกิน'])
        ->assertNotified(__('admin.credit.insufficient', ['balance' => '฿ 10']));
    Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'add', 'amount_satang' => '0', 'note' => 'ศูนย์'])
        ->assertHasActionErrors(['amount_satang']);
    Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $customer->id, 'direction' => 'add', 'amount_satang' => '5', 'note' => ''])
        ->assertHasActionErrors(['note']);

    // A staff id posted by hand is refused: whatever the page does with the miss, no entry is written.
    rescue(fn () => Livewire::test(ListStoreCreditEntries::class)
        ->callAction('adjust', ['user_id' => $staff->id, 'direction' => 'add', 'amount_satang' => '5', 'note' => 'พนักงาน']), report: false);

    expect($customer->refresh()->store_credit_satang)->toBe(1000)
        ->and($staff->refresh()->store_credit_satang)->toBe(0)
        ->and(StoreCreditEntry::query()->count())->toBe(1);
});
