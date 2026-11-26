<?php

use App\Actions\AddToCart;
use App\Actions\PlaceOrder;
use App\Enums\CardCondition;
use App\Enums\Courier;
use App\Enums\Finish;
use App\Enums\Language;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutBlocked;
use App\Livewire\Forms\CheckoutData;
use App\Livewire\Storefront\CartPanel;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Printing;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\OrderPlaced;
use App\Support\CartLines;
use App\Support\PromptPay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

beforeEach(function () {
    // Never read the developer's own .env: the tests say which methods the shop has configured.
    config([
        'shop.promptpay_id' => '0812345678',
        'shop.bank.name' => 'ธนาคารตัวอย่าง',
        'shop.bank.account_name' => 'ร้านตัวอย่าง',
        'shop.bank.account_number' => '000-0-00000-0',
    ]);
});

function checkoutVariant(int $qty = 3, ?int $priceSatang = 100000, string $name = 'Charizard ex'): Variant
{
    $card = Card::factory()->for(Game::factory())->create(['name_en' => $name]);
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create(['language' => Language::En, 'number' => '215']);

    return Variant::factory()->for($printing)->create([
        'condition' => CardCondition::Nm,
        'finish' => Finish::Foil,
        'qty' => $qty,
        'price_satang' => $priceSatang,
    ]);
}

/**
 * A cart that holds `$qty` of each variant, as AddToCart leaves it.
 *
 * @param  list<Variant>  $variants
 */
function checkoutCart(array $variants, int $qty = 1): Cart
{
    $cart = Cart::factory()->create();

    foreach ($variants as $variant) {
        app(AddToCart::class)->handle($cart, $variant, $qty);
    }

    return $cart;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function checkoutData(array $overrides = []): CheckoutData
{
    $data = new CheckoutData(new CheckoutForm, 'form');

    foreach ([
        'name' => ' สมชาย ใจดี ',
        'phone' => '081-234 5678',
        'email' => 'somchai@example.com',
        'line1' => '99/9 ซอยสุขุมวิท 1',
        'subdistrict' => 'คลองเตย',
        'district' => 'คลองเตย',
        'province' => 'กรุงเทพมหานคร',
        'postcode' => '10110',
        'courier' => 'flash',
        'protection' => false,
        'method' => 'promptpay',
        ...$overrides,
    ] as $property => $value) {
        $data->{$property} = $value;
    }

    return $data;
}

test('placing an order takes the stock, consumes the holds, empties the cart and writes the order, items and payment', function () {
    Notification::fake();
    $variant = checkoutVariant(qty: 3, priceSatang: 123400);
    $other = checkoutVariant(qty: 1, priceSatang: 5000, name: 'Mew');
    $cart = checkoutCart([$variant]);
    app(AddToCart::class)->handle($cart, $variant, 1);
    app(AddToCart::class)->handle($cart, $other, 1);

    $order = app(PlaceOrder::class)->handle($cart, checkoutData(['protection' => true]));

    expect($order->number)->toBe(Order::numberFor($order->id))
        ->and($order->number)->toMatch('/^TCG-\d{5}$/')
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->contact_name)->toBe('สมชาย ใจดี')
        ->and($order->contact_phone)->toBe('0812345678')
        ->and($order->address)->toBe([
            'recipient' => 'สมชาย ใจดี',
            'phone' => '0812345678',
            'line1' => '99/9 ซอยสุขุมวิท 1',
            'subdistrict' => 'คลองเตย',
            'district' => 'คลองเตย',
            'province' => 'กรุงเทพมหานคร',
            'postcode' => '10110',
        ])
        ->and($order->subtotal_satang)->toBe(2 * 123400 + 5000)
        ->and($order->total_satang)->toBe(2 * 123400 + 5000 + 4000 + 3000)
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->payment_due_at->equalTo($order->placed_at->copy()->addMinutes(30)))->toBeTrue()
        ->and($variant->refresh()->qty)->toBe(1)
        ->and($other->refresh()->qty)->toBe(0)
        ->and(StockReservation::query()->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(0)
        ->and($order->items)->toHaveCount(2)
        ->and($order->payments)->toHaveCount(1);

    $payment = $order->payments->first();
    expect($payment->method)->toBe(PaymentMethod::Promptpay)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount_satang)->toBe($order->total_satang)
        ->and($payment->qr_payload)->toBe(PromptPay::payload('0812345678', $order->total_satang));

    // The stock that was held is gone for real: nothing is available to anyone else.
    expect($variant->availableQty())->toBe(1)
        ->and($other->availableQty())->toBe(0);
});

test('an order keeps its names and prices after the card is renamed, the variant re-priced and then deleted', function () {
    Notification::fake();
    $variant = checkoutVariant(qty: 2, priceSatang: 429000, name: 'Charizard ex');
    $order = app(PlaceOrder::class)->handle(checkoutCart([$variant]), checkoutData());

    $variant->printing->card->update(['name_en' => 'Renamed']);
    $variant->update(['price_satang' => 1000, 'condition' => CardCondition::Hp]);
    $variant->delete();

    $item = $order->items()->firstOrFail();

    expect($item->card_name)->toBe('Charizard ex')
        ->and($item->set_code)->toBe($variant->printing->set->code)
        ->and($item->number)->toBe('215')
        ->and($item->language)->toBe('en')
        ->and($item->condition)->toBe('nm')
        ->and($item->finish)->toBe('foil')
        ->and($item->unit_price_satang)->toBe(429000)
        ->and($item->line_total_satang)->toBe(429000)
        ->and($item->purchasable_id)->toBe($variant->id)
        ->and(Order::query()->findOrFail($order->id)->subtotal_satang)->toBe(429000);
});

test('the order is charged the price the customer saw, not the variant price now', function () {
    Notification::fake();
    $variant = checkoutVariant(priceSatang: 100000);
    $cart = checkoutCart([$variant]);
    $variant->update(['price_satang' => 900000]);

    $order = app(PlaceOrder::class)->handle($cart, checkoutData());

    expect($order->subtotal_satang)->toBe(100000)
        ->and($order->items()->firstOrFail()->unit_price_satang)->toBe(100000);
});

test('totals add the courier rate and the packing only when it is on', function (Courier $courier, bool $protection) {
    Notification::fake();
    $order = app(PlaceOrder::class)->handle(
        checkoutCart([checkoutVariant(priceSatang: 100000)]),
        checkoutData(['courier' => $courier->value, 'protection' => $protection]),
    );

    expect($order->courier)->toBe($courier)
        ->and($order->shipping_satang)->toBe($courier->rateSatang())
        ->and($order->protection_satang)->toBe($protection ? 3000 : 0)
        ->and($order->total_satang)->toBe(100000 + $courier->rateSatang() + ($protection ? 3000 : 0))
        ->and($order->payments()->firstOrFail()->amount_satang)->toBe($order->total_satang);
})->with([
    'flash' => [Courier::Flash, false],
    'flash packed' => [Courier::Flash, true],
    'kerry' => [Courier::Kerry, false],
    'kerry packed' => [Courier::Kerry, true],
    'jt' => [Courier::Jt, false],
    'jt packed' => [Courier::Jt, true],
    'thaipost' => [Courier::Thaipost, false],
    'thaipost packed' => [Courier::Thaipost, true],
]);

test('the courier rates default to the design and come from config', function () {
    expect(Courier::Flash->rateSatang())->toBe(4000)
        ->and(Courier::Kerry->rateSatang())->toBe(5000)
        ->and(Courier::Jt->rateSatang())->toBe(4000)
        ->and(Courier::Thaipost->rateSatang())->toBe(3500);

    config(['shipping.couriers.kerry.rate_satang' => 6500]);

    expect(Courier::Kerry->rateSatang())->toBe(6500)
        ->and(Courier::Kerry->trackingUrl('ABC 1'))->toBe('https://th.kerryexpress.com/th/track/?track=ABC%201');
});

/**
 * Assert nothing was written and no stock moved, then that the cart is untouched.
 */
function expectNothingPlaced(Cart $cart, Variant $variant, int $qty): void
{
    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($variant->refresh()->qty)->toBe($qty)
        ->and($cart->items()->count())->toBeGreaterThan(0);
}

test('an expired line refuses the whole order and writes nothing', function () {
    Notification::fake();
    $fresh = checkoutVariant(qty: 2);
    $stale = checkoutVariant(qty: 2);
    $cart = checkoutCart([$fresh, $stale]);

    $this->travel(16)->minutes();
    app(AddToCart::class)->handle($cart, $fresh, 1);

    expect(fn () => app(PlaceOrder::class)->handle($cart, checkoutData()))->toThrow(CheckoutBlocked::class);

    expectNothingPlaced($cart, $stale, 2);
    expect($fresh->refresh()->qty)->toBe(2);
    Notification::assertNothingSent();
});

test('a line that can no longer be bought refuses the order', function (Closure $break) {
    Notification::fake();
    $variant = checkoutVariant(qty: 2);
    $cart = checkoutCart([$variant]);
    $break($variant);

    expect(fn () => app(PlaceOrder::class)->handle($cart, checkoutData()))->toThrow(CheckoutBlocked::class);

    expect(Order::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($cart->items()->count())->toBe(1);
})->with([
    'unpriced' => [fn (Variant $variant) => $variant->update(['price_satang' => null])],
    'deleted' => [fn (Variant $variant) => $variant->delete()],
    'card trashed' => [fn (Variant $variant) => $variant->printing->card->delete()],
]);

test('a line whose hold is smaller than the line, or stock lowered below the hold, refuses the order', function () {
    Notification::fake();
    $variant = checkoutVariant(qty: 3);
    $cart = checkoutCart([$variant], qty: 2);

    StockReservation::query()->where('cart_id', $cart->id)->update(['qty' => 1]);
    expect(fn () => app(PlaceOrder::class)->handle($cart, checkoutData()))->toThrow(CheckoutBlocked::class);

    StockReservation::query()->where('cart_id', $cart->id)->update(['qty' => 2]);
    $variant->update(['qty' => 1]);
    expect(fn () => app(PlaceOrder::class)->handle($cart, checkoutData()))->toThrow(CheckoutBlocked::class);

    expectNothingPlaced($cart, $variant, 1);
});

test('an empty cart refuses the order and writes nothing', function () {
    Notification::fake();
    $cart = Cart::factory()->create();

    expect(fn () => app(PlaceOrder::class)->handle($cart, checkoutData()))->toThrow(CheckoutBlocked::class)
        ->and(Order::query()->count())->toBe(0);
});

test('a payment method the shop does not offer is refused before anything is written', function () {
    Notification::fake();
    config(['shop.bank.account_number' => null]);
    $variant = checkoutVariant();
    $cart = checkoutCart([$variant]);

    expect(fn () => app(PlaceOrder::class)->handle($cart, checkoutData(['method' => 'bank_transfer'])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PlaceOrder::class)->handle($cart, checkoutData(['method' => 'card'])))->toThrow(InvalidArgumentException::class);

    expect(Order::query()->count())->toBe(0);
});

test('placing an order locks the variants before any other read inside its transaction', function () {
    Notification::fake();
    $cart = checkoutCart([checkoutVariant(), checkoutVariant()]);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(PlaceOrder::class)->handle($cart, checkoutData());

    $begin = array_search('BEGIN', $log, true);

    // SQLite writes no FOR UPDATE, so this proves the order (the variants select is the transaction's first
    // statement, one query for every line), not the lock itself.
    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "variants" where "id" in (')
        ->and(collect($log)->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "variants" where "id" in ('))->count())->toBeGreaterThanOrEqual(1);
});

test('the checkout page redirects to the cart without a cart or with nothing held', function () {
    $this->get(route('checkout'))->assertRedirect(route('cart'));

    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);
    $this->get(route('checkout'))->assertOk()->assertSee(__('storefront.checkout.confirm'));

    StockReservation::query()->update(['expires_at' => now()->subMinute()]);
    $this->get(route('checkout'))->assertRedirect(route('cart'));
});

test('the cart offers checkout only when every line is held and buyable', function () {
    $held = checkoutVariant();
    $cart = checkoutCart([$held]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.checkout'))
        ->assertSee(route('checkout'))
        ->assertDontSee(__('storefront.cart.checkout_blocked'));

    $stale = checkoutVariant();
    app(AddToCart::class)->handle($cart, $stale, 1);
    StockReservation::query()->where('purchasable_id', $stale->id)->update(['expires_at' => now()->subMinute()]);

    Livewire::test(CartPanel::class)
        ->assertDontSee(route('checkout'))
        ->assertSee(__('storefront.cart.checkout_blocked'));
});

test('the checkout form shows the lines, the lock banner and the summary', function () {
    $cart = checkoutCart([checkoutVariant(priceSatang: 123400, name: 'Charizard ex')]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->assertSee('Charizard ex')
        ->assertSee('฿ 1,234')
        ->assertSee('฿ 40')
        ->assertSee(__('storefront.checkout.terms', ['minutes' => 30]))
        ->assertSee('wire:key="lock-', false)
        ->assertSet('form.courier', 'flash')
        ->assertSet('form.method', 'promptpay');
});

test('packing defaults on when a held line costs at least the threshold, and off below it', function (int $priceSatang, bool $expected) {
    $cart = checkoutCart([checkoutVariant(priceSatang: $priceSatang)]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)->assertSet('form.protection', $expected);
})->with([
    'below the threshold' => [49900, false],
    'at the threshold' => [50000, true],
    'above it' => [429000, true],
]);

test('a payment method whose details are not configured is not offered', function () {
    config(['shop.promptpay_id' => '']);
    expect(PaymentMethod::offered())->toBe([PaymentMethod::BankTransfer]);

    config(['shop.bank.account_name' => '']);
    expect(PaymentMethod::offered())->toBe([]);

    config(['shop.promptpay_id' => '0812345678']);
    expect(PaymentMethod::offered())->toBe([PaymentMethod::Promptpay]);
});

test('with no method configured checkout says so and has no confirm button', function () {
    config(['shop.promptpay_id' => null, 'shop.bank.name' => null]);
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->assertSee(__('storefront.checkout.no_methods'))
        ->assertDontSee(__('storefront.checkout.confirm'));
});

test('the form validates the phone, the postcode, the email, the required fields and the offered methods', function () {
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->set('form.name', '')
        ->set('form.phone', '1234')
        ->set('form.email', 'not-an-email')
        ->set('form.line1', '  ')
        ->set('form.postcode', '1011')
        ->set('form.courier', 'pigeon')
        ->set('form.method', 'card')
        ->call('placeOrder')
        ->assertHasErrors(['form.name' => 'required', 'form.phone', 'form.email', 'form.line1' => 'required', 'form.subdistrict' => 'required', 'form.postcode', 'form.courier', 'form.method'])
        ->assertSee(__('storefront.checkout.errors.phone'))
        ->assertSee(__('storefront.checkout.errors.postcode'))
        ->assertSee(__('storefront.checkout.errors.method'));

    expect(Order::query()->count())->toBe(0);
});

test('a Thai phone is nine or ten digits after spaces and dashes and starts with zero', function (string $phone, bool $valid) {
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    $component = Livewire::test(CheckoutForm::class)->set('form.phone', $phone)->call('placeOrder');

    $valid ? $component->assertHasNoErrors('form.phone') : $component->assertHasErrors('form.phone');
})->with([
    'mobile with dashes' => ['081-234-5678', true],
    'mobile with spaces' => ['081 234 5678', true],
    'landline of nine' => ['022345678', true],
    'eight digits' => ['01234567', false],
    'eleven digits' => ['08123456789', false],
    'no leading zero' => ['812345678', false],
    'letters' => ['08x2345678', false],
]);

test('placing the order from the form stores the id in the session, redirects to the signed order page and notifies', function () {
    Notification::fake();
    $variant = checkoutVariant(qty: 2, priceSatang: 100000);
    $cart = checkoutCart([$variant]);
    session(['cart_token' => $cart->token]);

    $component = Livewire::test(CheckoutForm::class)
        ->set('form.name', 'สมชาย ใจดี')
        ->set('form.phone', '081-234-5678')
        ->set('form.email', 'somchai@example.com')
        ->set('form.line1', '99/9 ซอยสุขุมวิท 1')
        ->set('form.subdistrict', 'คลองเตย')
        ->set('form.district', 'คลองเตย')
        ->set('form.province', 'กรุงเทพมหานคร')
        ->set('form.postcode', '10110')
        ->set('form.courier', 'kerry')
        ->set('form.method', 'promptpay')
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->firstOrFail();

    $component->assertRedirect($order->signedUrl());
    expect(session('orders'))->toBe([$order->id])
        ->and($order->courier)->toBe(Courier::Kerry)
        ->and($variant->refresh()->qty)->toBe(1);

    Notification::assertSentOnDemand(OrderPlaced::class, function (OrderPlaced $notification, array $channels, AnonymousNotifiable $notifiable) use ($order): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'somchai@example.com'
            && $channels === ['mail']
            && $mail->actionUrl === $order->signedUrl()
            && str_contains($mail->subject, $order->number)
            && $notification instanceof ShouldQueue;
    });
});

test('a cart that went stale between the page and the click is refused with a link back, and nothing is written', function () {
    Notification::fake();
    $variant = checkoutVariant();
    $cart = checkoutCart([$variant]);
    session(['cart_token' => $cart->token]);

    $component = Livewire::test(CheckoutForm::class);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    $component
        ->set('form.name', 'สมชาย')
        ->set('form.phone', '0812345678')
        ->set('form.email', 'somchai@example.com')
        ->set('form.line1', '99/9')
        ->set('form.subdistrict', 'คลองเตย')
        ->set('form.district', 'คลองเตย')
        ->set('form.province', 'กรุงเทพมหานคร')
        ->set('form.postcode', '10110')
        ->call('placeOrder')
        ->assertNoRedirect()
        ->assertSet('refused', true)
        ->assertSee(__('storefront.checkout.blocked'))
        ->assertSee(route('cart'));

    expect(Order::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a held line with less stock than its quantity is shown as short, blocks checkout and is not orderable', function () {
    $variant = checkoutVariant(qty: 3);
    $cart = checkoutCart([$variant], qty: 2);
    session(['cart_token' => $cart->token]);
    $variant->update(['qty' => 1]);

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.stock_short'))
        ->assertDontSee(route('checkout'))
        ->assertSee(__('storefront.cart.checkout_blocked'));

    Livewire::test(CheckoutForm::class)
        ->assertSee(__('storefront.checkout.blocked_title'))
        ->assertDontSee(__('storefront.checkout.confirm'))
        ->assertDontSee(__('storefront.checkout.edit_cart'));

    expect((new CartLines($cart))->canPlaceOrder())->toBeFalse();
});

test('a line added to the cart while the order is placed survives, the checked lines are consumed', function () {
    Notification::fake();
    $placed = checkoutVariant(qty: 1);
    $late = checkoutVariant(qty: 1);
    $cart = checkoutCart([$placed]);

    $added = false;
    DB::beforeExecuting(function (string $query) use (&$added, $cart, $late): void {
        if ($added || ! str_starts_with($query, 'delete from "stock_reservations"')) {
            return;
        }

        $added = true;
        app(AddToCart::class)->handle($cart, $late, 1);
    });

    $order = app(PlaceOrder::class)->handle($cart, checkoutData());

    expect($added)->toBeTrue()
        ->and($order->items)->toHaveCount(1)
        ->and($cart->items()->pluck('purchasable_id')->all())->toBe([$late->id])
        ->and($cart->reservations()->pluck('purchasable_id')->all())->toBe([$late->id]);
});

test('a fixed field clears its error when it loses focus, and a failed submit asks the page to focus the first error', function () {
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    $component = Livewire::test(CheckoutForm::class)
        ->set('form.phone', '12')
        ->assertHasErrors('form.phone')
        ->set('form.phone', '0812345678')
        ->assertHasNoErrors('form.phone')
        ->call('placeOrder')
        ->assertHasErrors('form.name')
        ->assertDispatched('checkout-invalid');

    expect($component->html())->toContain('wire:model.live.blur="form.phone"')->toContain('maxlength="5"')->toContain('enterkeyhint="next"');
});

test('a PromptPay order is placed with the QR payload for its total, valid for qr_minutes inside the payment window', function () {
    Notification::fake();
    $order = app(PlaceOrder::class)->handle(checkoutCart([checkoutVariant(priceSatang: 123400)]), checkoutData());
    $payment = $order->payments()->sole();

    expect($payment->qr_payload)->toBe(PromptPay::payload('0812345678', $order->total_satang))
        ->and($payment->qr_expires_at->equalTo($order->placed_at->copy()->addMinutes(15)))->toBeTrue();
});

test('the first QR never outlives the payment window, and a bank transfer has none', function () {
    Notification::fake();
    config(['shop.payment_window_minutes' => 10]);

    $order = app(PlaceOrder::class)->handle(checkoutCart([checkoutVariant()]), checkoutData());
    expect($order->payments()->sole()->qr_expires_at->equalTo($order->payment_due_at))->toBeTrue();

    $bank = app(PlaceOrder::class)->handle(checkoutCart([checkoutVariant()]), checkoutData(['method' => 'bank_transfer']));
    $payment = $bank->payments()->sole();
    expect($payment->qr_payload)->toBeNull()->and($payment->qr_expires_at)->toBeNull();
});

test('PromptPay is offered only when the configured id is a valid PromptPay id', function () {
    foreach (['0812345678', '+66812345678', '1234567890123'] as $id) {
        config(['shop.promptpay_id' => $id]);
        expect(PaymentMethod::offered())->toContain(PaymentMethod::Promptpay);
    }

    foreach (['12345', 'abc', ''] as $id) {
        config(['shop.promptpay_id' => $id]);
        expect(PaymentMethod::offered())->not->toContain(PaymentMethod::Promptpay);
    }
});

test('a signed-in customer\'s order belongs to them and a guest\'s order has no owner', function () {
    Notification::fake();
    $user = User::factory()->create();

    $mine = app(PlaceOrder::class)->handle(checkoutCart([checkoutVariant()]), checkoutData(), $user);
    $guest = app(PlaceOrder::class)->handle(checkoutCart([checkoutVariant()]), checkoutData());

    expect($mine->user_id)->toBe($user->id)->and($guest->user_id)->toBeNull();
});

test('checkout prefills the name and email of a signed-in customer and places the order for them', function () {
    Notification::fake();
    $user = User::factory()->create(['name' => 'Somchai Jaidee', 'email' => 'somchai@example.com']);
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    $this->actingAs($user);
    Livewire::test(CheckoutForm::class)
        ->assertSet('form.name', 'Somchai Jaidee')
        ->assertSet('form.email', 'somchai@example.com')
        ->set('form.phone', '0812345678')
        ->set('form.line1', '99/9 ซอยสุขุมวิท 1')
        ->set('form.subdistrict', 'คลองเตย')
        ->set('form.district', 'คลองเตย')
        ->set('form.province', 'กรุงเทพมหานคร')
        ->set('form.postcode', '10110')
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->latest('id')->first();
    expect($order->user_id)->toBe($user->id)
        ->and(session('orders_placed'))->toBe([$order->id])
        ->and(session('orders'))->toBe([$order->id]);
});

test('checkout leaves the fields empty for a guest and for a customer without an email', function () {
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)->assertSet('form.name', '')->assertSet('form.email', '');

    $this->actingAs(User::factory()->withLine()->create(['email' => null, 'password' => null]));
    Livewire::test(CheckoutForm::class)->assertSet('form.email', '');
});

test('the phone confirm bar stays tappable while a text field has focus', function () {
    // Hiding the bar on input focus made a tap from the last field land on the page behind it: the order never went out.
    $cart = checkoutCart([checkoutVariant()]);
    session(['cart_token' => $cart->token]);

    $html = Livewire::test(CheckoutForm::class)->html();

    expect($html)->toContain('wire:submit="placeOrder"')
        ->and($html)->not->toContain('data-text-input]:focus]:hidden');
});
