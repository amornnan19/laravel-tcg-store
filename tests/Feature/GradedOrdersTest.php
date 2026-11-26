<?php

require_once __DIR__.'/../Support/graded.php';

use App\Actions\CancelOrder;
use App\Actions\PlaceOrder;
use App\Actions\RefundOrder;
use App\Enums\GradedStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutBlocked;
use App\Filament\Resources\Orders\OrderResource;
use App\Livewire\Storefront\CartPanel;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\GradedItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

beforeEach(function () {
    config([
        'shop.promptpay_id' => '0812345678',
        'shipping.graded_insurance_satang' => 20000,
    ]);
});

test('placing an order sells the graded item, snapshots it as graded and leaves the single fields empty', function () {
    Notification::fake();
    $item = gradedItem(['grade' => 9.5, 'cert_number' => '12344821', 'price_satang' => 800000]);
    $cart = gradedCart([$item]);

    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());

    $line = $order->items()->firstOrFail();

    expect($item->fresh()->status)->toBe(GradedStatus::Sold)
        ->and($line->kind)->toBe('graded')
        ->and($line->detail)->toBe('PSA 9.5 · cert •••• 4821')
        ->and($line->detail)->not->toContain('12344821')
        ->and($line->condition)->toBeNull()
        ->and($line->finish)->toBeNull()
        ->and($line->purchasable_type)->toBe('graded')
        ->and($line->purchasable_id)->toBe($item->id)
        ->and($line->unit_price_satang)->toBe(800000)
        ->and(StockReservation::query()->count())->toBe(0)
        ->and($cart->items()->count())->toBe(0);
});

test('a graded item without a certificate number snapshots only the grade', function () {
    $item = GradedItem::factory()->for(gradedPrinting())->withdrawn()->withoutCert()->create();

    expect($item->snapshot()['detail'])->toBe('PSA 10');
});

test('insurance is the configured amount per graded item, added to the total and to the first payment', function () {
    Notification::fake();
    config(['shipping.graded_insurance_satang' => 25000]);
    $cart = gradedCart([gradedItem(['price_satang' => 100000]), gradedItem(['price_satang' => 200000]), gradedVariant(priceSatang: 50000)]);

    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());

    expect($order->insurance_satang)->toBe(50000)
        ->and($order->subtotal_satang)->toBe(350000)
        ->and($order->total_satang)->toBe(350000 + 4000 + 50000)
        ->and($order->payments()->firstOrFail()->amount_satang)->toBe($order->total_satang);
});

test('an order of singles only carries no insurance', function () {
    Notification::fake();

    $order = app(PlaceOrder::class)->handle(gradedCart([gradedVariant(priceSatang: 50000)]), gradedCheckoutData());

    expect($order->insurance_satang)->toBe(0)
        ->and($order->total_satang)->toBe(50000 + 4000);
});

test('a graded id equal to a variant id sells the graded item and takes only the variant line quantity', function () {
    Notification::fake();
    $variant = gradedVariant(qty: 5);
    $item = gradedItem(['id' => $variant->id]);
    expect($item->id)->toBe($variant->id);

    $order = app(PlaceOrder::class)->handle(gradedCart([$variant, $item]), gradedCheckoutData());

    expect($variant->fresh()->qty)->toBe(4)
        ->and($item->fresh()->status)->toBe(GradedStatus::Sold)
        ->and($order->items()->orderBy('id')->pluck('kind')->all())->toBe(['single', 'graded']);
});

test('a mixed cart locks the variants and then the graded items before any other read', function () {
    Notification::fake();
    $cart = gradedCart([gradedVariant(), gradedItem()]);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(PlaceOrder::class)->handle($cart, gradedCheckoutData());

    $begin = array_search('BEGIN', $log, true);

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "variants" where "id" in (')
        ->and($log[$begin + 2])->toStartWith('select * from "graded_items" where "id" in (');
});

test('an order refuses a graded line whose item was withdrawn since', function () {
    Notification::fake();
    $item = gradedItem();
    $cart = gradedCart([$item]);
    $item->update(['status' => GradedStatus::Withdrawn]);

    expect(fn () => app(PlaceOrder::class)->handle($cart, gradedCheckoutData()))->toThrow(CheckoutBlocked::class)
        ->and(Order::query()->count())->toBe(0)
        ->and($item->fresh()->status)->toBe(GradedStatus::Withdrawn);
});

test('cancelling returns the graded item to listed and the variant its quantity', function () {
    Notification::fake();
    $variant = gradedVariant(qty: 5);
    $item = gradedItem();
    $order = app(PlaceOrder::class)->handle(gradedCart([$variant, $item]), gradedCheckoutData());

    expect($item->fresh()->status)->toBe(GradedStatus::Sold)->and($variant->fresh()->qty)->toBe(4);

    app(CancelOrder::class)->handle($order, 'ลูกค้าขอยกเลิก');

    expect($item->fresh()->status)->toBe(GradedStatus::Listed)
        ->and($variant->fresh()->qty)->toBe(5);
});

test('refunding returns the graded item to listed and the variant its quantity', function () {
    Notification::fake();
    $variant = gradedVariant(qty: 5);
    $item = gradedItem();
    $order = app(PlaceOrder::class)->handle(gradedCart([$variant, $item]), gradedCheckoutData());
    $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $order->payments()->update(['status' => PaymentStatus::Confirmed->value, 'confirmed_at' => now()]);

    app(RefundOrder::class)->handle($order, 'ของไม่ตรง');

    expect($item->fresh()->status)->toBe(GradedStatus::Listed)
        ->and($variant->fresh()->qty)->toBe(5);
});

test('a returned graded item whose photos were cleared since is withdrawn instead of listed', function () {
    Notification::fake();
    $item = gradedItem();
    $order = app(PlaceOrder::class)->handle(gradedCart([$item]), gradedCheckoutData());
    $item->fresh()->update(['front_path' => null]);

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    expect($item->fresh()->status)->toBe(GradedStatus::Withdrawn);
});

test('cancelling skips a graded item that was deleted since', function () {
    Notification::fake();
    $item = gradedItem();
    $order = app(PlaceOrder::class)->handle(gradedCart([$item]), gradedCheckoutData());
    $item->delete();

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('the cart shows a graded line with its grade, no stepper and the checkout link', function () {
    $item = gradedItem(['price_satang' => 800000], gradedPrinting('Pikachu'));
    $cart = gradedCart([$item]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CartPanel::class)
        ->assertSee('Pikachu')
        ->assertSee('PSA 10')
        ->assertSee('฿ 8,000')
        ->assertSee(route('graded.show', $item))
        ->assertSee(__('storefront.cart.one_of_a_kind'))
        ->assertDontSee(__('storefront.card.increase'))
        ->assertSee(route('checkout'));
});

test('the cart treats a withdrawn graded line as gone', function () {
    $item = gradedItem();
    $cart = gradedCart([$item]);
    session(['cart_token' => $cart->token]);
    $item->update(['status' => GradedStatus::Withdrawn]);

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.gone'))
        ->assertDontSee(route('checkout'));
});

test('checkout lists the insurance line and counts it in the total', function () {
    $cart = gradedCart([gradedItem(['price_satang' => 800000])]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->assertSee(__('storefront.checkout.insurance', ['n' => 1]))
        ->assertSee(__('storefront.checkout.insurance_note'))
        ->assertSee('฿ 200')
        ->assertSee('฿ 8,240');
});

test('checkout shows no insurance line for singles', function () {
    $cart = gradedCart([gradedVariant()]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)->assertDontSee(__('storefront.checkout.insurance_note'));
});

test('the order page and the admin item list render single, graded and sealed snapshots', function () {
    $order = Order::factory()
        ->has(OrderItem::factory()->state(['card_name' => 'Single Card', 'number' => '119', 'language' => 'ja', 'condition' => 'nm', 'finish' => 'foil']), 'items')
        ->has(OrderItem::factory()->graded()->state(['card_name' => 'Graded Card', 'number' => '215']), 'items')
        ->has(OrderItem::factory()->sealed()->state(['card_name' => 'Sealed Box']), 'items')
        ->create(['insurance_satang' => 20000]);

    $this->get($order->signedUrl())
        ->assertOk()
        ->assertSee('Single Card')
        ->assertSee('Graded Card')
        ->assertSee('PSA 10 · cert •••• 4821')
        ->assertSee('Sealed Box')
        ->assertSee('Booster Box')
        ->assertSee(__('storefront.checkout.insurance', ['n' => 1]));

    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create())
        ->get(OrderResource::getUrl('view', ['record' => $order]))
        ->assertOk()
        ->assertSee('Single Card')
        ->assertSee('Graded Card')
        ->assertSee('PSA 10 · cert •••• 4821')
        ->assertSee('Sealed Box')
        ->assertSee('Booster Box');
});

test('a cart of graded cards only has no packing option, and a forced one is not charged', function () {
    Notification::fake();
    $cart = gradedCart([gradedItem(['price_satang' => 800000])]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->assertSet('form.protection', false)
        ->assertDontSee('wire:model.live="form.protection"', false)
        ->assertSee(__('storefront.checkout.insurance_row'));

    // A client can still send the flag: the summary does not count it either.
    Livewire::test(CheckoutForm::class)
        ->set('form.protection', true)
        ->assertDontSee(__('storefront.checkout.protection'))
        ->assertSee('฿ 8,240');

    $data = gradedCheckoutData();
    $data->protection = true;
    $order = app(PlaceOrder::class)->handle($cart, $data);

    expect($order->protection_satang)->toBe(0)
        ->and($order->total_satang)->toBe(800000 + 4000 + 20000);
});

test('a mixed cart keeps the packing option, for singles, and charges it', function () {
    Notification::fake();
    $cart = gradedCart([gradedItem(['price_satang' => 800000]), gradedVariant(priceSatang: 100000)]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->assertSet('form.protection', true)
        ->assertSee('wire:model.live="form.protection"', false)
        ->assertSee(__('storefront.checkout.protection_hint_mixed'))
        ->assertSee(__('storefront.checkout.insurance_row'));

    $data = gradedCheckoutData();
    $data->protection = true;
    $order = app(PlaceOrder::class)->handle($cart, $data);

    expect($order->protection_satang)->toBe(3000);
});

test('an expensive graded card alone does not turn the packing default on', function () {
    $cart = gradedCart([gradedItem(['price_satang' => 9000000]), gradedVariant(priceSatang: 1000)]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)->assertSet('form.protection', false);
});

test('the cart summary lists the hard box as a row with its amount and totals before shipping', function () {
    $cart = gradedCart([gradedItem(['price_satang' => 800000])]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.checkout.insurance', ['n' => 1]))
        ->assertSee('฿ 200')
        ->assertSee(__('storefront.cart.total_before_shipping'))
        ->assertSee('฿ 8,200');

    $singles = gradedCart([gradedVariant(priceSatang: 100000)]);
    session(['cart_token' => $singles->token]);

    Livewire::test(CartPanel::class)
        ->assertDontSee(__('storefront.checkout.insurance', ['n' => 1]))
        ->assertSee(__('storefront.cart.total_before_shipping'));
});

test('the cart, checkout and order lines show the real front photo of a graded card', function () {
    Notification::fake();
    config(['shop.promptpay_id' => '0812345678']);
    $item = gradedItem();
    $cart = gradedCart([$item]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CartPanel::class)->assertSee('graded-photos/front.jpg', false);
    Livewire::test(CheckoutForm::class)->assertSee('graded-photos/front.jpg', false);

    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());

    $this->get($order->signedUrl())->assertOk()->assertSee('graded-photos/front.jpg', false);
});
