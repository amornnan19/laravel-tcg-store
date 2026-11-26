<?php

use App\Actions\AddToCart;
use App\Actions\CancelOrder;
use App\Actions\ConfirmPayment;
use App\Actions\PlaceOrder;
use App\Actions\RecordStoreCredit;
use App\Actions\RefundOrder;
use App\Actions\ReturnOrderCredit;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreCreditReason;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PaymentRefused;
use App\Exceptions\StoreCreditChanged;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Jobs\CancelUnpaidOrders;
use App\Livewire\Forms\CheckoutData;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\Game;
use App\Models\Order;
use App\Models\Printing;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\OrderCancelled;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderRefunded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['shop.promptpay_id' => '0812345678']);
});

/** A one-card cart at ฿1,000, so an order with Flash shipping (฿40) totals 104000 satang. */
function creditCart(): Cart
{
    $card = Card::factory()->for(Game::factory())->create();
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create(['language' => Language::En, 'number' => '1']);
    $variant = Variant::factory()->for($printing)->create(['condition' => CardCondition::Nm, 'finish' => Finish::Normal, 'qty' => 5, 'price_satang' => 100000]);

    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $variant, 1);

    return $cart;
}

function creditCheckoutData(bool $useCredit, int $shownCreditSatang = 0): CheckoutData
{
    $data = new CheckoutData(new CheckoutForm, 'form');

    foreach ([
        'name' => 'สมชาย ใจดี', 'phone' => '0812345678', 'email' => 'somchai@example.com', 'line1' => '99/9 ซอยสุขุมวิท 1',
        'subdistrict' => 'คลองเตย', 'district' => 'คลองเตย', 'province' => 'กรุงเทพมหานคร', 'postcode' => '10110',
        'courier' => 'flash', 'protection' => false, 'method' => 'promptpay', 'useCredit' => $useCredit, 'shownCreditSatang' => $shownCreditSatang,
    ] as $property => $value) {
        $data->{$property} = $value;
    }

    return $data;
}

function creditHolder(int $satang): User
{
    $user = User::factory()->create();

    if ($satang > 0) {
        app(RecordStoreCredit::class)->handle($user, $satang, StoreCreditReason::Adjustment, note: 'ตั้งต้น');
    }

    return $user->refresh();
}

/** Places a ฿1,040 order; the credit the customer "saw" defaults to what the form would show: min(balance, total). */
function placeWithCredit(User $user, bool $useCredit = true, ?int $shown = null): Order
{
    $shown ??= $useCredit ? min($user->store_credit_satang, 104000) : 0;

    return app(PlaceOrder::class)->handle(creditCart(), creditCheckoutData($useCredit, $shown), $user);
}

test('the credit checkbox shows only to a signed-in customer with a balance', function () {
    $cart = creditCart();
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)->assertDontSee(__('storefront.checkout.credit.title'));

    $this->actingAs(creditHolder(0));
    Livewire::test(CheckoutForm::class)->assertDontSee(__('storefront.checkout.credit.title'));

    $this->actingAs(creditHolder(30000));
    Livewire::test(CheckoutForm::class)
        ->assertSee(__('storefront.checkout.credit.title'))
        ->assertSee('มีเครดิต ฿ 300 · ใช้ได้ ฿ 300 กับคำสั่งซื้อนี้')
        ->assertSet('form.useCredit', false)
        ->assertDontSee(__('storefront.checkout.total_transfer'))
        ->set('form.useCredit', true)
        ->assertSee('เครดิตร้าน')
        ->assertSee('−฿ 300')
        ->assertSee(__('storefront.checkout.total_transfer'));
});

test('the usable amount is capped at the order total', function () {
    $cart = creditCart();
    session(['cart_token' => $cart->token]);

    $this->actingAs(creditHolder(500000));

    Livewire::test(CheckoutForm::class)->assertSee('มีเครดิต ฿ 5,000 · ใช้ได้ ฿ 1,070 กับคำสั่งซื้อนี้');
});

test('credit is off by default: nothing is spent unless the box is ticked', function () {
    $user = creditHolder(30000);

    $order = placeWithCredit($user, useCredit: false);

    expect($order->credit_used_satang)->toBe(0)
        ->and($order->payments()->sole()->amount_satang)->toBe(104000)
        ->and($user->refresh()->store_credit_satang)->toBe(30000)
        ->and($user->storeCreditEntries()->count())->toBe(1);
});

test('a partial spend asks for the rest as the payment and records an order entry with its source', function () {
    $user = creditHolder(30000);

    $order = placeWithCredit($user);

    $entry = $user->storeCreditEntries()->latest('id')->first();

    expect($order->total_satang)->toBe(104000)
        ->and($order->credit_used_satang)->toBe(30000)
        ->and($order->payableSatang())->toBe(74000)
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->payments()->sole()->amount_satang)->toBe(74000)
        ->and($user->refresh()->store_credit_satang)->toBe(0)
        ->and($entry->reason)->toBe(StoreCreditReason::Order)
        ->and($entry->delta_satang)->toBe(-30000)
        ->and($entry->balance_after_satang)->toBe(0)
        ->and($entry->source_type)->toBe('order')
        ->and($entry->source_id)->toBe($order->id);
});

test('credit never goes beyond the order total, and the rest stays on the account', function () {
    $user = creditHolder(500000);

    $order = placeWithCredit($user);

    expect($order->credit_used_satang)->toBe(104000)
        ->and($user->refresh()->store_credit_satang)->toBe(500000 - 104000);
});

test('a guest ticking the box spends nothing', function () {
    $order = app(PlaceOrder::class)->handle(creditCart(), creditCheckoutData(true));

    expect($order->credit_used_satang)->toBe(0)
        ->and($order->payments()->sole()->amount_satang)->toBe(104000);
});

test('credit that covers the whole order pays it at once with no payment row, and the page says so', function () {
    $user = creditHolder(104000);

    $order = placeWithCredit($user);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->payments()->count())->toBe(0)
        ->and($order->payableSatang())->toBe(0)
        ->and($order->remainingSatang())->toBe(0)
        ->and($user->refresh()->store_credit_satang)->toBe(0);

    Notification::assertSentOnDemand(OrderPlaced::class);

    $this->get($order->signedUrl())
        ->assertOk()
        ->assertSee(__('storefront.order.paid_by_credit'))
        ->assertDontSee(__('storefront.payment.section'));
});

test('confirming a slip for the payable amount pays the order rather than leaving it short', function () {
    $user = creditHolder(30000);
    $order = placeWithCredit($user);
    $payment = $order->payments()->sole();
    $payment->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);

    app(ConfirmPayment::class)->handle($payment);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->receivedSatang())->toBe(74000)
        ->and($order->remainingSatang())->toBe(0);
});

test('cancelling an unpaid order through the expiry job returns the credit, once', function () {
    $user = creditHolder(30000);
    $order = placeWithCredit($user);
    $order->update(['payment_due_at' => now()->subMinute()]);

    app()->call([new CancelUnpaidOrders, 'handle']);
    app()->call([new CancelUnpaidOrders, 'handle']);

    $entries = $user->storeCreditEntries()->where('reason', StoreCreditReason::Refund->value)->get();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->credit_returned_at)->not->toBeNull()
        ->and($entries)->toHaveCount(1)
        ->and($entries->first()->delta_satang)->toBe(30000)
        ->and($entries->first()->source_id)->toBe($order->id)
        ->and($user->refresh()->store_credit_satang)->toBe(30000);

    Notification::assertSentOnDemand(OrderCancelled::class, fn (OrderCancelled $notification, $channels, AnonymousNotifiable $to) => $notification->creditReturnedSatang === 30000
        && str_contains(implode(' ', $notification->toMail($to)->introLines), '฿ 300'));
});

test('returning the credit of an order a second time does nothing', function () {
    $user = creditHolder(30000);
    $order = placeWithCredit($user);

    expect(app(ReturnOrderCredit::class)->handle($order))->toBe(30000)
        ->and(app(ReturnOrderCredit::class)->handle($order->refresh()))->toBe(0)
        ->and($user->refresh()->store_credit_satang)->toBe(30000);
});

test('refunding a paid order returns the credit as credit and leaves the cash amount unchanged', function () {
    $user = creditHolder(30000);
    $order = placeWithCredit($user);
    $payment = $order->payments()->sole();
    $payment->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);
    app(ConfirmPayment::class)->handle($payment);

    $result = app(RefundOrder::class)->handle($order->refresh(), 'ลูกค้าขอคืน');

    expect($result->refundedSatang)->toBe(74000)
        ->and($result->creditReturnedSatang)->toBe(30000)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($user->refresh()->store_credit_satang)->toBe(30000);

    Notification::assertSentOnDemand(OrderRefunded::class, fn (OrderRefunded $notification, $channels, AnonymousNotifiable $to) => $notification->creditReturnedSatang === 30000
        && str_contains(implode(' ', $notification->toMail($to)->introLines), '฿ 300'));

    expect(fn () => app(RefundOrder::class)->handle($order->refresh(), 'อีกครั้ง'))->toThrow(IllegalOrderTransition::class)
        ->and($user->refresh()->store_credit_satang)->toBe(30000);
});

test('refunding an order paid wholly with credit returns only credit', function () {
    $user = creditHolder(104000);
    $order = placeWithCredit($user);

    $result = app(RefundOrder::class)->handle($order, 'ลูกค้าขอคืน');

    expect($result->refundedSatang)->toBe(0)
        ->and($result->creditReturnedSatang)->toBe(104000)
        ->and($user->refresh()->store_credit_satang)->toBe(104000);
});

test('a cancel that is refused because a slip is waiting returns no credit', function () {
    $user = creditHolder(30000);
    $order = placeWithCredit($user);
    $order->payments()->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);

    expect(fn () => app(CancelOrder::class)->handle($order, 'ยกเลิก'))->toThrow(PaymentRefused::class);

    expect($user->refresh()->store_credit_satang)->toBe(0)
        ->and($order->refresh()->credit_returned_at)->toBeNull();
});

test('the admin cancel and refund dialogs and notices name the credit that goes back', function () {
    $user = creditHolder(30000);
    $order = placeWithCredit($user);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('cancel')
        ->assertMountedActionModalSee('คืนเครดิตร้าน ฿ 300')
        ->setActionData(['reason' => 'ลูกค้าขอยกเลิก'])
        ->callMountedAction()
        ->assertNotified(
            Filament\Notifications\Notification::make()->success()->title(__('admin.orders.done.cancel'))
                ->body(__('admin.orders.return_credit', ['amount' => '฿ 300']))->persistent(),
        );

    expect($user->refresh()->store_credit_satang)->toBe(30000);
});

test('a credit spend that differs from what the customer saw is refused with nothing written', function (int $balanceNow, int $shown) {
    $user = creditHolder($balanceNow);
    $cart = creditCart();
    $variant = Variant::query()->sole();

    expect(fn () => app(PlaceOrder::class)->handle($cart, creditCheckoutData(true, $shown), $user))
        ->toThrow(StoreCreditChanged::class);

    expect(Order::query()->count())->toBe(0)
        ->and($user->refresh()->store_credit_satang)->toBe($balanceNow)
        ->and($user->storeCreditEntries()->count())->toBe(1)
        ->and($variant->refresh()->qty)->toBe(5)
        ->and($cart->items()->count())->toBe(1);
})->with([
    'the balance dropped after the page rendered' => [10000, 30000],
    'the balance rose after the page rendered' => [50000, 30000],
]);

test('the form refuses a changed balance, says so next to the confirm button and shows the new figures', function () {
    $user = creditHolder(30000);
    session(['cart_token' => creditCart()->token]);
    $this->actingAs($user);

    // Every request re-renders and refreshes the figure the customer sees, so the form is filled in first.
    $component = Livewire::test(CheckoutForm::class)
        ->set('form.name', 'สมชาย')->set('form.phone', '0812345678')->set('form.email', 'a@example.com')
        ->set('form.line1', '1')->set('form.subdistrict', 'ก')->set('form.district', 'ข')->set('form.province', 'ค')->set('form.postcode', '10110')
        ->set('form.useCredit', true)
        ->assertSet('form.shownCreditSatang', 30000);

    app(RecordStoreCredit::class)->handle($user, 20000, StoreCreditReason::Adjustment, note: 'เพิ่ม');

    $component
        ->call('placeOrder')
        ->assertSet('creditChanged', true)
        ->assertSee(__('storefront.checkout.credit.changed'))
        ->assertSet('form.shownCreditSatang', 50000)
        ->assertNoRedirect();

    expect(Order::query()->count())->toBe(0);

    $component->call('placeOrder')->assertSet('creditChanged', false);

    expect(Order::query()->sole()->credit_used_satang)->toBe(50000);
});

test('the checkout labels the figures: total, credit, then the amount to transfer; the phone bar says transfer', function () {
    session(['cart_token' => creditCart()->token]);
    $this->actingAs(creditHolder(30000));

    $plain = Livewire::test(CheckoutForm::class)->assertSee('เครดิตร้าน')->assertSee(__('storefront.checkout.total_due'))->assertDontSee(__('storefront.checkout.total_transfer'));
    $html = $plain->set('form.useCredit', true)->html();
    $text = preg_replace('/\s+/', '', strip_tags($html));

    expect($text)->toContain('ยอดที่ต้องโอน')
        ->and($text)->not->toContain(preg_replace('/\s+/', '', __('storefront.checkout.total_due')))
        ->and(substr_count($text, 'ยอดที่ต้องโอน'))->toBe(2)
        ->and(strpos($text, 'ยอดรวม฿1,070'))->toBeLessThan(strpos($text, 'เครดิตร้าน−฿300'))
        ->and(strpos($text, 'เครดิตร้าน−฿300'))->toBeLessThan(strrpos($text, __('storefront.checkout.total_transfer').'฿770'));
    expect($html)->toContain('id="checkout-credit-heading"');
});

test('with credit covering everything the payment methods are hidden and the terms say nothing is transferred', function () {
    session(['cart_token' => creditCart()->token]);
    $this->actingAs(creditHolder(500000));

    Livewire::test(CheckoutForm::class)
        ->assertSee(__('storefront.checkout.payment'))
        ->assertSee(__('storefront.checkout.terms', ['minutes' => 30]))
        ->set('form.useCredit', true)
        ->assertDontSee(__('storefront.checkout.payment'))
        ->assertDontSee(__('storefront.checkout.terms', ['minutes' => 30]))
        ->assertSee(__('storefront.checkout.terms_credit'))
        ->assertSet('form.method', 'promptpay')
        ->set('form.name', 'สมชาย')->set('form.phone', '0812345678')->set('form.email', 'a@example.com')
        ->set('form.line1', '1')->set('form.subdistrict', 'ก')->set('form.district', 'ข')->set('form.province', 'ค')->set('form.postcode', '10110')
        ->call('placeOrder')
        ->assertHasNoErrors();

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid);
});

test('the order page lays the summary out as total, credit, then the amount to transfer, and orders without credit are unchanged', function () {
    $order = placeWithCredit(creditHolder(30000));

    $text = preg_replace('/\s+/', '', strip_tags($this->get($order->signedUrl())->assertOk()->getContent()));

    expect(strpos($text, 'ยอดรวม฿1,040'))->toBeLessThan(strpos($text, 'เครดิตร้าน−฿300'))
        ->and(strpos($text, 'เครดิตร้าน−฿300'))->toBeLessThan(strpos($text, 'ยอดที่ต้องโอน฿740'));

    $plain = placeWithCredit(creditHolder(0), useCredit: false);

    $this->get($plain->signedUrl())->assertOk()->assertDontSee('เครดิตร้าน −')->assertDontSee(__('storefront.order.payable'));
});

test('a fully covered order shows one green band after placing, and its email button does not ask for payment', function () {
    $order = placeWithCredit(creditHolder(104000));

    $html = $this->withSession(['order_placed' => $order->id])->get($order->signedUrl())->assertOk()->getContent();

    expect(substr_count($html, 'border-stock/40'))->toBe(1)
        ->and($html)->toContain(__('storefront.order.placed_flash'))
        ->and($html)->toContain(__('storefront.order.paid_by_credit'));

    Notification::assertSentOnDemand(OrderPlaced::class, fn (OrderPlaced $notification, $channels, AnonymousNotifiable $to) => $notification->toMail($to)->actionText === __('notifications.order_placed.action_paid'));
});

test('the account order list says what the credit was for an order that used it', function () {
    $user = creditHolder(30000);
    placeWithCredit($user);

    $this->actingAs($user->refresh())->get(route('account'))
        ->assertSee('ยอดรวม ฿ 1,040 · ใช้เครดิต ฿ 300')
        ->assertSee(__('storefront.account.credit.latest'));
});
