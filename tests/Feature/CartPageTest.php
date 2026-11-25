<?php

use App\Actions\AddToCart;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Livewire\Storefront\CartLink;
use App\Livewire\Storefront\VariantTable;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\Printing;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

/**
 * @return array{card: Card, printing: Printing, variant: Variant}
 */
function cartPageShop(int $qty = 3, int $priceSatang = 100000): array
{
    $card = Card::factory()->for(Game::factory())->create(['name_en' => 'Charizard ex']);
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create(['language' => Language::En]);
    $variant = Variant::factory()->for($printing)->create([
        'condition' => CardCondition::Nm,
        'finish' => Finish::Normal,
        'qty' => $qty,
        'price_satang' => $priceSatang,
    ]);

    return ['card' => $card, 'printing' => $printing, 'variant' => $variant];
}

test('adding from the card page creates the cart, the session token, the line and the reservation', function () {
    ['card' => $card, 'printing' => $printing, 'variant' => $variant] = cartPageShop(qty: 3);

    expect(Cart::query()->count())->toBe(0);

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->call('increment')
        ->call('addToCart')
        ->assertDispatched('cart-updated')
        ->assertSet('added', true)
        ->assertSet('qty', 1)
        ->assertSee(__('storefront.card.added'))
        // 3 on hand, 2 now held by this cart: the stepper cap and its sentence shrink to 1.
        ->assertSee(__('storefront.card.cap_reached', ['n' => 1, 'minutes' => config('shop.reservation_minutes')]))
        ->assertSee(__('storefront.card.in_cart', ['n' => 2]));

    $cart = Cart::query()->sole();
    $item = CartItem::query()->sole();
    $reservation = StockReservation::query()->sole();

    expect(session('cart_token'))->toBe($cart->token)
        ->and($item->cart_id)->toBe($cart->id)
        ->and($item->purchasable_id)->toBe($variant->id)
        ->and($item->qty)->toBe(2)
        ->and($item->unit_price_satang)->toBe(100000)
        ->and($reservation->qty)->toBe(2)
        ->and($reservation->isLive())->toBeTrue()
        ->and($variant->availableQty())->toBe(1);
});

test('a row the cart holds entirely reads sold out with its in-cart count', function () {
    ['card' => $card, 'printing' => $printing] = cartPageShop(qty: 1);

    $table = Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->call('addToCart')
        ->assertSee(__('storefront.card.in_cart', ['n' => 1]))
        ->assertSee(__('storefront.stock.out_of_stock'))
        ->assertSee(__('storefront.card.none_available'));

    expect($table->html())->toContain(__('storefront.card.added'));
});

test('a cart that holds nothing of the card shows no in-cart line', function () {
    ['card' => $card, 'printing' => $printing, 'variant' => $variant] = cartPageShop(qty: 3);
    StockReservation::factory()->create(['purchasable_id' => $variant->id, 'qty' => 1]);

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->assertDontSee(__('storefront.card.in_cart', ['n' => 1]))
        ->assertSee(__('storefront.stock.low_stock', ['n' => 2]));
});

test('insufficient stock shows how many are left and adds nothing', function (int $left, string $sentence) {
    ['card' => $card, 'printing' => $printing] = cartPageShop(qty: 3);

    $this->mock(AddToCart::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new InsufficientStock($left)));

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->call('addToCart')
        ->assertSee(__($sentence, ['n' => $left]))
        ->assertSet('added', false)
        ->assertNotDispatched('cart-updated');
})->with([
    'some left' => [2, 'storefront.card.add_failed_some'],
    'none left' => [0, 'storefront.card.add_failed_none'],
]);

test('a row that cannot be bought shows the generic failure sentence', function () {
    ['card' => $card, 'printing' => $printing] = cartPageShop();

    $this->mock(AddToCart::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new NotPurchasable));

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->call('addToCart')
        ->assertSee(__('storefront.card.add_failed'))
        ->assertNotDispatched('cart-updated');
});

test('changing the selection clears the add notice', function () {
    ['card' => $card, 'printing' => $printing] = cartPageShop(qty: 3);

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->call('addToCart')
        ->assertSet('added', true)
        ->call('increment')
        ->assertSet('added', false)
        ->assertSet('notice', null);
});

test('the cart link shows the line count after an add and no badge with an empty session', function () {
    ['card' => $card, 'printing' => $printing] = cartPageShop(qty: 3);

    Livewire::test(CartLink::class, ['variant' => 'header'])
        ->assertSee(__('storefront.nav.cart'))
        ->assertDontSee('aria-label', false);

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])->call('addToCart');

    foreach (['header', 'bottom'] as $variant) {
        Livewire::test(CartLink::class, ['variant' => $variant])
            ->assertSeeHtml('aria-label="'.__('storefront.nav.cart_count', ['n' => 1]).'"')
            ->assertSeeHtml('>1</span>');
    }
});

test('the cart link recounts when the cart is updated', function () {
    $cart = Cart::factory()->create();
    session(['cart_token' => $cart->token]);

    $link = Livewire::test(CartLink::class, ['variant' => 'header'])->assertDontSeeHtml('aria-label=');

    foreach ([cartPageShop()['variant'], cartPageShop()['variant']] as $variant) {
        app(AddToCart::class)->handle($cart, $variant, 1);
    }

    $link->assertDontSeeHtml('aria-label="'.__('storefront.nav.cart_count', ['n' => 2]).'"')
        ->dispatch('cart-updated')
        ->assertSeeHtml('aria-label="'.__('storefront.nav.cart_count', ['n' => 2]).'"');
});

test('the cart link runs no query without a cart token', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(CartLink::class, ['variant' => 'header'])->assertOk();
    Livewire::test(CartLink::class, ['variant' => 'bottom'])->assertOk();

    expect(DB::getQueryLog())->toBe([]);

    session(['cart_token' => 'unknown']);
    DB::flushQueryLog();

    Livewire::test(CartLink::class)->assertDontSeeHtml('aria-label=');

    expect(DB::getQueryLog())->toHaveCount(1);
});

test('the bottom nav links to the cart and marks it current on the cart page', function () {
    $this->get(route('home'))->assertSeeHtml('href="'.route('cart').'"');

    $html = $this->get(route('cart'))->assertOk()->content();

    expect($html)->toMatch('/href="'.preg_quote(route('cart'), '/').'"\s+aria-current="page"/');
});

test('the cart page shows the empty state, or the lines read-only', function () {
    $this->get(route('cart'))
        ->assertOk()
        ->assertSee(__('storefront.cart.empty'))
        ->assertSee(__('storefront.cart.search'));

    ['variant' => $variant] = cartPageShop(qty: 3, priceSatang: 123400);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $variant, 2);
    session(['cart_token' => $cart->token]);

    $this->get(route('cart'))
        ->assertOk()
        ->assertSee('Charizard ex')
        ->assertSee('฿ 2,468')
        ->assertSee(__('storefront.cart.lines', ['n' => 1]))
        ->assertDontSee(__('storefront.cart.empty'));
});

test('a successful add offers a link to the cart', function () {
    ['card' => $card, 'printing' => $printing] = cartPageShop(qty: 3);

    Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->assertDontSee(__('storefront.card.view_cart'))
        ->call('addToCart')
        ->assertSeeHtml('href="'.route('cart').'"')
        ->assertSee(__('storefront.card.view_cart'));
});

test('an add acts on the chosen row and never on the fallback when it was taken meanwhile', function () {
    ['card' => $card, 'printing' => $printing, 'variant' => $nm] = cartPageShop(qty: 1, priceSatang: 100000);
    $lp = Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'finish' => Finish::Normal, 'qty' => 2, 'price_satang' => 150000]);

    $table = Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])->assertSet('variantId', $nm->id);

    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $table->call('addToCart')
        ->assertSee(__('storefront.card.add_failed_none'))
        ->assertSet('added', false)
        ->assertSet('variantId', $lp->id)
        ->assertNotDispatched('cart-updated');

    expect(CartItem::query()->count())->toBe(0)->and(StockReservation::query()->where('purchasable_id', $lp->id)->exists())->toBeFalse();
});

test('after adding the last copy the selection moves to the next row and the button reads add again', function () {
    ['card' => $card, 'printing' => $printing, 'variant' => $nm] = cartPageShop(qty: 1, priceSatang: 100000);
    $lp = Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'finish' => Finish::Normal, 'qty' => 2, 'price_satang' => 150000]);

    $table = Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id])
        ->call('addToCart')
        ->assertSet('variantId', $lp->id)
        ->assertSet('added', false)
        ->assertSee(__('storefront.card.view_cart'));

    expect(CartItem::query()->pluck('purchasable_id')->all())->toBe([$nm->id])
        ->and(substr_count($table->html(), __('storefront.card.add')))->toBeGreaterThanOrEqual(2);

    $table->call('addToCart');

    expect(CartItem::query()->orderBy('id')->pluck('purchasable_id')->all())->toBe([$nm->id, $lp->id]);
});
