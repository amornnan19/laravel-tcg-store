<?php

require_once __DIR__.'/../Support/preorder.php';

use App\Actions\PackOrder;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PreorderPlan;
use App\Filament\Actions\PaymentActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Preorders\Pages\CreatePreorder;
use App\Filament\Resources\Preorders\Pages\EditPreorder;
use App\Filament\Resources\Preorders\Pages\ListPreorders;
use App\Filament\Resources\Preorders\PreorderResource;
use App\Filament\Resources\Variants\VariantResource;
use App\Jobs\RequestPreorderBalances;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['shop.promptpay_id' => '0812345678']);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
});

test('the list shows progress for staff and is forbidden for a customer', function () {
    $preorder = openPreorder(['quota' => 40, 'reserved_count' => 12]);

    $this->get(PreorderResource::getUrl('index'))->assertOk()->assertSee('12 / 40')->assertSee($preorder->sealedProduct->name_en);
    $this->get(PreorderResource::getUrl('edit', ['record' => $preorder]))->assertOk();

    $this->actingAs(User::factory()->create());

    $this->get(PreorderResource::getUrl('index'))->assertForbidden();
    $this->get(PreorderResource::getUrl('create'))->assertForbidden();
});

test('the resource sits in the catalogue group after the variants', function () {
    expect(PreorderResource::getNavigationGroup())->toBe('แคตตาล็อก')
        ->and(PreorderResource::getPluralModelLabel())->toBe('พรีออเดอร์')
        ->and(PreorderResource::getNavigationSort())->toBeGreaterThan(VariantResource::getNavigationSort());
});

test('staff create a pre-order with prices in baht and the counter starts at zero', function () {
    $product = sealedProduct(['is_preorder' => true]);

    Livewire::test(CreatePreorder::class)
        ->fillForm([
            'sealed_product_id' => $product->id,
            'release_on' => now()->addDays(30)->toDateString(),
            'cancel_until' => now()->addDays(10)->toDateString(),
            'deposit_satang' => '500',
            'full_satang' => '2800.50',
            'full_discount_bp' => 200,
            'quota' => 40,
            'per_account_limit' => 2,
            'is_open' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $preorder = Preorder::query()->firstOrFail();

    expect($preorder->deposit_satang)->toBe(50000)
        ->and($preorder->full_satang)->toBe(280050)
        ->and($preorder->reserved_count)->toBe(0)
        ->and($preorder->is_open)->toBeTrue();
});

test('the form refuses a deposit above the full price, a deadline after the release and a quota below what is taken', function () {
    $preorder = openPreorder(['quota' => 10, 'reserved_count' => 6]);

    Livewire::test(EditPreorder::class, ['record' => $preorder->getKey()])
        ->fillForm(['deposit_satang' => '3000', 'full_satang' => '2800', 'cancel_until' => $preorder->release_on->copy()->addDay()->toDateString(), 'quota' => 5])
        ->call('save')
        ->assertHasFormErrors(['full_satang', 'cancel_until', 'quota']);

    expect($preorder->fresh()->quota)->toBe(10);
});

test('saving the form never changes the reserved counter', function () {
    $preorder = openPreorder(['quota' => 10, 'reserved_count' => 6]);

    Livewire::test(EditPreorder::class, ['record' => $preorder->getKey()])
        ->fillForm(['quota' => 12])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($preorder->fresh()->quota)->toBe(12)->and($preorder->fresh()->reserved_count)->toBe(6);
});

test('only an unused pre-order product can be picked, and one product has one pre-order', function () {
    $taken = openPreorder();
    $free = sealedProduct(['is_preorder' => true]);
    $plain = sealedProduct();

    Livewire::test(CreatePreorder::class)
        ->assertFormFieldExists('sealed_product_id', fn ($field) => array_keys($field->getOptions()) === [$free->id]);
});

test('the orders list filters by kind and the desk page shows the pre-order block with the deadline rule', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->subDay()->toDateString()]);
    $preorderOrder = confirmLatestPayment(placePreorder($preorder));
    $stockOrder = Order::factory()->create();

    Livewire::test(ListOrders::class)
        ->filterTable('kind', OrderKind::Preorder->value)
        ->assertCanSeeTableRecords([$preorderOrder])
        ->assertCanNotSeeTableRecords([$stockOrder]);

    Livewire::test(ViewOrder::class, ['record' => $preorderOrder->getRouteKey()])
        ->assertSee('พรีออเดอร์')
        ->assertSee('ส่วนที่เหลือ')
        ->mountAction('cancel')
        ->assertMountedActionModalSee('เลยกำหนด')
        ->assertMountedActionModalSee('ร้านเก็บมัดจำ ฿ 500')
        ->assertMountedActionModalSee('โควต้าที่จองไว้จะถูกคืน');

    $this->get(OrderResource::getUrl('view', ['record' => $preorderOrder]))->assertOk();
});

test('inside the deadline the cancel modal says the deposit is refunded in full', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->addDays(3)->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('cancel')
        ->assertMountedActionModalSee('คืนมัดจำเต็มจำนวน')
        ->assertMountedActionModalSee('ต้องคืนเงินให้ลูกค้า ฿ 500')
        ->setActionData(['reason' => 'ลูกค้าขอยกเลิก'])
        ->callMountedAction()
        ->assertNotified();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)->and($preorder->fresh()->reserved_count)->toBe(0);
});

test('an overdue balance is listed by its filter and flagged on the desk', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(2)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    (new RequestPreorderBalances)->handle();
    $order->refresh()->update(['payment_due_at' => now()->subHour()]);
    $other = Order::factory()->create();

    Livewire::test(ListOrders::class)
        ->filterTable('overdue_balance', true)
        ->assertCanSeeTableRecords([$order])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertSee('เกินกำหนดชำระส่วนที่เหลือแล้ว');
});

test('the opens-at picker works in shop time', function () {
    Livewire::test(CreatePreorder::class)
        ->assertFormFieldExists('opens_at', fn (DateTimePicker $field): bool => $field->getTimezone() === config('shop.timezone'));
});

test('the list names a pre-order whose product is in the trash', function () {
    $preorder = openPreorder();
    $name = $preorder->sealedProduct->name_en;
    $preorder->sealedProduct->delete();

    $this->get(PreorderResource::getUrl('index'))->assertOk()->assertSee($name);
    Livewire::test(ListPreorders::class)->assertCanSeeTableRecords([$preorder]);
    $this->get(PreorderResource::getUrl('edit', ['record' => $preorder]))->assertOk()->assertSee($name);
});

test('the pack button waits for the release day of a pre-order', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('pack');

    $preorder->update(['release_on' => now(config('shop.timezone'))->toDateString()]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionVisible('pack');
});

test('a pack attempt before the release day is refused with its own message and changes nothing', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));

    // The button is hidden, but a stale page can still send the action: the desk answers with the release rule.
    PaymentActions::attempt(fn () => app(PackOrder::class)->handle($order));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    FilamentNotification::assertNotified(__('admin.orders.preorder_not_released'));
});

test('saving a moved release date gives the requested balances their new deadline, and saving another field does not', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    (new RequestPreorderBalances)->handle();
    $order->refresh();
    $first = $order->payment_due_at->copy();
    $newRelease = now(config('shop.timezone'))->addDays(10)->toDateString();

    Livewire::test(EditPreorder::class, ['record' => $preorder->getKey()])
        ->fillForm(['quota' => 60])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->payment_due_at->equalTo($first))->toBeTrue();

    Livewire::test(EditPreorder::class, ['record' => $preorder->getKey()])
        ->fillForm(['release_on' => $newRelease])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->payment_due_at->format('Y-m-d H:i:s'))->toBe($preorder->refresh()->releaseDayEnd()->utc()->format('Y-m-d H:i:s'))
        ->and($order->payment_due_at->gt($first))->toBeTrue();
});

test('the reschedule runs after the panel save has committed, not inside it', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    confirmLatestPayment(placePreorder($preorder));
    $log = [];

    // The transaction depth at each statement: the save's own transaction is one deep, and the reschedule opens one of its
    // own per order. Inside the save it would be two deep; after the commit it is back to one.
    DB::listen(function ($query) use (&$log): void {
        $log[] = [$query->sql, DB::transactionLevel()];
    });

    Livewire::test(EditPreorder::class, ['record' => $preorder->getKey()])
        ->fillForm(['release_on' => now(config('shop.timezone'))->addDays(10)->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = collect($log)->search(fn (array $entry): bool => str_starts_with($entry[0], 'update "preorders"'));
    $reschedule = collect($log)->search(fn (array $entry, int $index): bool => $index > $saved && str_contains($entry[0], 'from "orders" where "orders"."id" = ?'));

    expect($saved)->not->toBeFalse()
        ->and($reschedule)->not->toBeFalse()
        ->and($log[$reschedule][1])->toBe($log[$saved][1]);
});
