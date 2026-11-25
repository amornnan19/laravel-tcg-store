<?php

use App\Actions\AddToCart;
use App\Actions\ChangeCartQuantity;
use App\Actions\RenewCartItem;
use App\Actions\ReserveStock;
use App\Actions\SwapCartItem;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Enums\RenewOutcome;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Jobs\ReleaseExpiredReservations;
use App\Livewire\Storefront\CartLink;
use App\Livewire\Storefront\CartPanel;
use App\Livewire\Storefront\VariantTable;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\Printing;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

/**
 * One printing with an NM variant, and a second condition when asked for.
 *
 * @return array{printing: Printing, variant: Variant}
 */
function panelShop(int $qty = 3, int $priceSatang = 100000, string $name = 'Charizard ex'): array
{
    $card = Card::factory()->for(Game::factory())->create(['name_en' => $name]);
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create(['language' => Language::En]);
    $variant = Variant::factory()->for($printing)->create([
        'condition' => CardCondition::Nm,
        'finish' => Finish::Normal,
        'qty' => $qty,
        'price_satang' => $priceSatang,
    ]);

    return ['printing' => $printing, 'variant' => $variant];
}

function panelVariant(Printing $printing, CardCondition $condition, int $qty, int $priceSatang): Variant
{
    return Variant::factory()->for($printing)->create([
        'condition' => $condition,
        'finish' => Finish::Normal,
        'qty' => $qty,
        'price_satang' => $priceSatang,
    ]);
}

/**
 * A cart in the session with a line of `$qty` of the variant, locked by AddToCart.
 */
function panelCart(Variant $variant, int $qty = 1, ?Cart $cart = null): CartItem
{
    $cart ??= Cart::factory()->create();
    session(['cart_token' => $cart->token]);

    return app(AddToCart::class)->handle($cart, $variant, $qty);
}

test('the cart page with no session shows the empty state and reads no cart table', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('cart'))
        ->assertOk()
        ->assertSee(__('storefront.cart.empty'))
        ->assertSee(__('storefront.cart.search'))
        ->assertDontSee(__('storefront.cart.lock', ['time' => '']));

    $cartQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/cart_items|stock_reservations|"carts"/', $query['query']) === 1);

    expect($cartQueries)->toBeEmpty();
});

test('the cart page lists the lines with prices, the subtotal, the count and the lock banner', function () {
    ['variant' => $charizard] = panelShop(qty: 3, priceSatang: 123400);
    ['variant' => $pikachu] = panelShop(qty: 2, priceSatang: 50000, name: 'Pikachu');
    $cart = Cart::factory()->create();
    panelCart($charizard, 2, $cart);
    panelCart($pikachu, 1, $cart);

    $this->get(route('cart'))
        ->assertOk()
        ->assertSee('Charizard ex')
        ->assertSee('Pikachu')
        ->assertSee('฿ 2,468')
        ->assertSee('฿ 500')
        ->assertSee('฿ 2,968')
        ->assertSee(__('storefront.cart.lines', ['n' => 2]))
        ->assertSee(__('storefront.cart.items_total', ['n' => 2]))
        ->assertSee(__('storefront.cart.total'))
        ->assertSee('ล็อกของในตะกร้าไว้อีก')
        ->assertSee('15:00')
        ->assertDontSee(__('storefront.cart.empty'));
});

test('an increment past what is available says so and changes nothing', function () {
    ['variant' => $variant] = panelShop(qty: 2);
    $item = panelCart($variant, 1);
    StockReservation::factory()->create(['purchasable_id' => $variant->id, 'qty' => 1]);

    Livewire::test(CartPanel::class)
        ->call('increment', $item->id)
        ->assertSee(__('storefront.cart.at_cap'))
        ->assertSeeHtml('aria-disabled="true"');

    expect($item->fresh()->qty)->toBe(1)
        ->and(StockReservation::query()->where('cart_id', $item->cart_id)->sole()->qty)->toBe(1);
});

test('increment adds one and decrement never goes below one', function () {
    ['variant' => $variant] = panelShop(qty: 5);
    $item = panelCart($variant, 1);

    $panel = Livewire::test(CartPanel::class)
        ->call('increment', $item->id)
        ->assertDispatched('cart-updated');

    expect($item->fresh()->qty)->toBe(2)
        ->and($variant->fresh()->availableQty())->toBe(3);

    $panel->call('decrement', $item->id)->call('decrement', $item->id)->call('decrement', $item->id);

    expect($item->fresh()->qty)->toBe(1)
        ->and(StockReservation::query()->where('cart_id', $item->cart_id)->sole()->qty)->toBe(1);
});

test('remove deletes the line and its reservation and dispatches cart-updated', function () {
    ['variant' => $variant] = panelShop(qty: 2);
    $item = panelCart($variant, 1);

    Livewire::test(CartPanel::class)
        ->call('remove', $item->id)
        ->assertDispatched('cart-updated')
        ->assertSee(__('storefront.cart.empty'));

    expect(CartItem::query()->count())->toBe(0)->and(StockReservation::query()->count())->toBe(0);
});

test('an item id of another cart is a 404 for every action and touches nothing', function (string $action, array $extra) {
    ['variant' => $variant] = panelShop(qty: 3);
    $mine = panelCart($variant, 1);
    ['variant' => $other] = panelShop(qty: 3);
    $foreign = panelCart($other, 1);
    session(['cart_token' => $mine->cart->token]);

    Livewire::test(CartPanel::class)
        ->call($action, $foreign->id, ...$extra)
        ->assertNotFound();

    expect($foreign->fresh()->qty)->toBe(1)
        ->and(CartItem::query()->count())->toBe(2)
        ->and(StockReservation::query()->count())->toBe(2);
})->with([
    'increment' => ['increment', []],
    'decrement' => ['decrement', []],
    'remove' => ['remove', []],
    'renew' => ['renew', []],
    'swap' => ['swap', [1]],
]);

test('an action with no cart in the session is a 404', function () {
    Livewire::test(CartPanel::class)->call('remove', 1)->assertNotFound();
});

test('after the lock time the line shows expired, leaves the subtotal and the banner goes', function () {
    ['variant' => $variant] = panelShop(qty: 3, priceSatang: 100000);
    panelCart($variant, 2);

    Livewire::test(CartPanel::class)->assertSee('฿ 2,000')->assertSee('ล็อกของในตะกร้าไว้อีก');

    $this->travel(16)->minutes();

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.expired'))
        ->assertSee(__('storefront.cart.renew'))
        ->assertSee(__('storefront.cart.lines', ['n' => 1]))
        ->assertDontSee(__('storefront.cart.summary'))
        ->assertDontSee('ล็อกของในตะกร้าไว้อีก')
        ->assertDontSeeHtml('aria-label="'.__('storefront.card.increase').'"');
});

test('a line that expires between the render and the click shows the expired state instead of an error', function (string $action) {
    ['variant' => $variant] = panelShop(qty: 5);
    $item = panelCart($variant, 2);

    $panel = Livewire::test(CartPanel::class)->assertSee('ล็อกของในตะกร้าไว้อีก');

    $this->travel(16)->minutes();

    $panel->call($action, $item->id)
        ->assertOk()
        ->assertSee(__('storefront.cart.expired'))
        ->assertDontSee('ล็อกของในตะกร้าไว้อีก');

    expect($item->fresh()->qty)->toBe(2);
})->with(['increment', 'decrement']);

test('renewing with stock holds it again, refreshes the price and says when it moved', function () {
    ['variant' => $variant] = panelShop(qty: 3, priceSatang: 100000);
    $item = panelCart($variant, 2);
    $variant->update(['price_satang' => 120000]);
    $this->travel(16)->minutes();

    Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->assertDispatched('cart-updated')
        ->assertSee(__('storefront.cart.price_changed', ['from' => '฿ 1,000', 'to' => '฿ 1,200']))
        ->assertDontSee(__('storefront.cart.qty_reduced', ['n' => 2]))
        ->assertDontSee(__('storefront.cart.expired'))
        ->assertSee('฿ 2,400');

    expect($item->fresh()->qty)->toBe(2)
        ->and($item->fresh()->unit_price_satang)->toBe(120000)
        ->and($item->reservation()->first()->isLive())->toBeTrue();
});

test('renewing with a price that did not move says nothing about price', function () {
    ['variant' => $variant] = panelShop(qty: 3);
    $item = panelCart($variant, 1);
    $this->travel(16)->minutes();

    Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->assertDontSee('ราคาเปลี่ยนจาก')
        ->assertDontSee(__('storefront.cart.expired'));
});

test('renewing with fewer copies than the line asked for reduces the quantity and says so', function () {
    ['variant' => $variant] = panelShop(qty: 3);
    $item = panelCart($variant, 3);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $variant->id, 'qty' => 2]);

    Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->assertSee(__('storefront.cart.qty_reduced', ['n' => 1]));

    expect($item->fresh()->qty)->toBe(1)
        ->and($item->reservation()->first()->qty)->toBe(1);
});

test('renewing with nothing left offers the cheaper condition of the same printing, and swap replaces the line', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 680000, name: 'Pikachu ex');
    $lp = panelVariant($printing, CardCondition::Lp, qty: 2, priceSatang: 610000);
    panelVariant($printing, CardCondition::Mp, qty: 2, priceSatang: 700000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->assertSee(__('storefront.cart.taken', ['name' => 'Pikachu ex', 'variant' => 'NM · ปกติ']))
        ->assertSee(__('storefront.cart.substitute_cheaper', ['variant' => 'LP · ปกติ', 'price' => '฿ 6,100', 'diff' => '฿ 700']))
        ->assertSee(__('storefront.cart.swap', ['variant' => 'LP · ปกติ']))
        ->assertSee(__('storefront.cart.remove_line'))
        ->assertDontSee(__('storefront.cart.renew'));

    expect(StockReservation::query()->where('cart_id', $item->cart_id)->live()->exists())->toBeFalse();

    $panel->call('swap', $item->id, $lp->id)
        ->assertDispatched('cart-updated')
        ->assertDontSee(__('storefront.cart.taken', ['name' => 'Pikachu ex', 'variant' => 'NM · ปกติ']))
        ->assertSee('฿ 6,100');

    $line = CartItem::query()->sole();

    expect($line->purchasable_id)->toBe($lp->id)
        ->and($line->qty)->toBe(1)
        ->and($line->unit_price_satang)->toBe(610000)
        ->and($line->reservation()->first()->isLive())->toBeTrue()
        ->and(CartItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('a dearer substitute says so, and with no substitute only the remove button is offered', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 100000);
    $mp = panelVariant($printing, CardCondition::Mp, qty: 1, priceSatang: 130000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->assertSee(__('storefront.cart.substitute_dearer', ['variant' => 'MP · ปกติ', 'price' => '฿ 1,300', 'diff' => '฿ 300']));

    StockReservation::factory()->create(['purchasable_id' => $mp->id, 'qty' => 1]);

    $panel->call('renew', $item->id)
        ->assertSee(__('storefront.cart.taken', ['name' => $printing->card->name_en, 'variant' => 'NM · ปกติ']))
        ->assertDontSee(__('storefront.cart.substitute_dearer', ['variant' => 'MP · ปกติ', 'price' => '฿ 1,300', 'diff' => '฿ 300']))
        ->assertDontSee(__('storefront.cart.swap', ['variant' => 'MP · ปกติ']))
        ->assertSee(__('storefront.cart.remove_line'));
});

test('a tampered swap variant id changes nothing', function (string $target) {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 680000);
    $lp = panelVariant($printing, CardCondition::Lp, qty: 2, priceSatang: 610000);
    $mp = panelVariant($printing, CardCondition::Mp, qty: 2, priceSatang: 650000);
    ['variant' => $elsewhere] = panelShop(qty: 5, priceSatang: 10000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)->call('renew', $item->id);

    $variantId = ['not offered' => $mp->id, 'other printing' => $elsewhere->id, 'itself' => $nm->id, 'missing' => 999999][$target];

    $panel->call('swap', $item->id, $variantId);

    expect(CartItem::query()->sole()->purchasable_id)->toBe($nm->id)
        ->and(StockReservation::query()->where('cart_id', $item->cart_id)->live()->exists())->toBeFalse()
        ->and($lp->availableQty())->toBe(2);
})->with(['not offered', 'other printing', 'itself', 'missing']);

test('a swap does nothing for a line that holds stock again', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 2, priceSatang: 680000);
    $lp = panelVariant($printing, CardCondition::Lp, qty: 2, priceSatang: 610000);
    $item = panelCart($nm, 1);

    Livewire::test(CartPanel::class)->call('swap', $item->id, $lp->id);

    expect(CartItem::query()->sole()->purchasable_id)->toBe($nm->id);
});

test('a line whose card was trashed shows as gone, counts as a line and is left out of the subtotal', function () {
    ['printing' => $trashed, 'variant' => $gone] = panelShop(qty: 2, priceSatang: 900000, name: 'Gone Card');
    ['variant' => $kept] = panelShop(qty: 2, priceSatang: 50000, name: 'Kept Card');
    $cart = Cart::factory()->create();
    $goneLine = panelCart($gone, 1, $cart);
    panelCart($kept, 1, $cart);
    $trashed->card->delete();

    $panel = Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.lines', ['n' => 2]))
        ->assertSee(__('storefront.cart.gone'))
        ->assertSee('Kept Card')
        ->assertSee('Gone Card')
        ->assertSee(__('storefront.cart.items_total', ['n' => 1]))
        ->assertDontSee('฿ 9,000')
        ->assertDontSee(__('storefront.cart.renew'))
        ->assertDontSee(__('storefront.cart.expired'))
        ->assertDontSeeHtml('href="'.route('card.show', ['card' => $trashed->card_id]).'"');

    expect(substr_count($panel->html(), 'wire:click="remove('))->toBe(2);

    // The header badge counts the same two rows.
    Livewire::test(CartLink::class)->assertSeeHtml('aria-label="'.__('storefront.nav.cart_count', ['n' => 2]).'"');

    $panel->call('remove', $goneLine->id)->assertDontSee(__('storefront.cart.gone'));
});

test('a line whose printing was trashed is gone as well', function () {
    ['printing' => $printing, 'variant' => $variant] = panelShop();
    panelCart($variant, 1);
    $printing->delete();

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.gone'))
        ->assertSee(__('storefront.cart.lines', ['n' => 1]))
        ->assertDontSee(__('storefront.cart.summary'));
});

test('renewing an item that can no longer be bought reports it gone and keeps the line', function () {
    ['variant' => $variant] = panelShop(qty: 2);
    $item = panelCart($variant, 1);
    $this->travel(16)->minutes();
    $variant->update(['price_satang' => null]);

    expect(app(RenewCartItem::class)->handle($item)->outcome)->toBe(RenewOutcome::Gone);

    Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->assertSee(__('storefront.cart.gone'))
        ->assertDontSee(__('storefront.cart.renew'));

    expect(CartItem::query()->count())->toBe(1);
});

test('the countdown runs to the soonest hold and a new deadline gets a fresh element', function () {
    ['variant' => $first] = panelShop(qty: 3);
    ['variant' => $second] = panelShop(qty: 3);
    $cart = Cart::factory()->create();
    $firstItem = panelCart($first, 1, $cart);
    $this->travel(5)->minutes();
    panelCart($second, 1, $cart);

    $panel = Livewire::test(CartPanel::class)->assertSee('10:00');

    $this->travel(1)->minutes();
    // Raising the first line restarts its hold, so the soonest deadline is now the second line's.
    $panel->call('increment', $firstItem->id)->assertSee('14:00');
});

test('releasing expired reservations deletes only the expired rows', function () {
    $live = StockReservation::factory()->create();
    $expired = StockReservation::factory()->expired()->create();

    (new ReleaseExpiredReservations)->handle();

    expect(StockReservation::query()->pluck('id')->all())->toBe([$live->id])
        ->and(StockReservation::query()->whereKey($expired->id)->exists())->toBeFalse();
});

test('the release job is queued and scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->description, ReleaseExpiredReservations::class));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(new ReleaseExpiredReservations)->toBeInstanceOf(ShouldQueue::class);
});

test('the cart link re-renders as exactly one link under one root, for both variants', function (string $variant) {
    ['variant' => $item] = panelShop();
    panelCart($item, 1);

    $link = Livewire::test(CartLink::class, ['variant' => $variant])->dispatch('cart-updated');
    $html = trim($link->html());

    expect(substr_count($html, 'href="'.route('cart').'"'))->toBe(1)
        ->and($html)->toStartWith('<a')
        ->and($html)->toEndWith('</a>')
        ->and(substr_count($html, __('storefront.nav.cart').'<'))->toBeLessThanOrEqual(1);
})->with(['header', 'bottom']);

test('a stepper at its limit does not carry the enabled colour', function () {
    ['variant' => $variant] = panelShop(qty: 1);
    panelCart($variant, 1);

    $html = Livewire::test(CartPanel::class)->html();

    preg_match_all('/<button[^>]*aria-label="(?:'.preg_quote(__('storefront.card.decrease'), '/').'|'.preg_quote(__('storefront.card.increase'), '/').')"[^>]*>/u', $html, $buttons);

    expect($buttons[0])->toHaveCount(2);

    foreach ($buttons[0] as $button) {
        expect($button)->toContain('text-faint')->not->toContain('text-ink');
    }
});

test('a live line whose price was withdrawn or whose game was deactivated shows as gone', function (string $withdrawal) {
    ['printing' => $printing, 'variant' => $variant] = panelShop(qty: 3, priceSatang: 100000);
    ['variant' => $kept] = panelShop(qty: 3, priceSatang: 50000, name: 'Kept Card');
    $cart = Cart::factory()->create();
    panelCart($variant, 1, $cart);
    panelCart($kept, 1, $cart);

    match ($withdrawal) {
        'price' => $variant->update(['price_satang' => null]),
        'game' => $printing->set->game->update(['is_active' => false]),
    };

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.gone'))
        ->assertSee(__('storefront.cart.items_total', ['n' => 1]))
        ->assertSee(__('storefront.cart.excluded', ['n' => 1]))
        ->assertDontSee('฿ 1,000');
})->with(['price', 'game']);

test('no path raises a hold on an item that is no longer purchasable', function () {
    ['variant' => $variant] = panelShop(qty: 5);
    $item = panelCart($variant, 1);
    $variant->update(['price_satang' => null]);

    expect(fn () => app(ChangeCartQuantity::class)->handle($item, 2))->toThrow(NotPurchasable::class)
        ->and(fn () => app(AddToCart::class)->handle($item->cart, $variant, 1))->toThrow(NotPurchasable::class)
        ->and($item->fresh()->qty)->toBe(1);

    Livewire::test(CartPanel::class)->call('increment', $item->id)->assertOk();

    expect($item->fresh()->qty)->toBe(1);
});

test('a stale swap shows the fresh renew result: the original came back', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 680000);
    $lp = panelVariant($printing, CardCondition::Lp, qty: 2, priceSatang: 610000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    $other = StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)->call('renew', $item->id)->assertSee(__('storefront.cart.swap', ['variant' => 'LP · ปกติ']));

    $other->delete();

    $panel->call('swap', $item->id, $lp->id)
        ->assertDontSee(__('storefront.cart.swap', ['variant' => 'LP · ปกติ']))
        ->assertDontSee(__('storefront.cart.expired'));

    expect(CartItem::query()->sole()->purchasable_id)->toBe($nm->id)
        ->and($item->reservation()->first()->isLive())->toBeTrue();
});

test('a stale swap shows the fresh renew result: a cheaper variant appeared', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 680000);
    $mp = panelVariant($printing, CardCondition::Mp, qty: 2, priceSatang: 700000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)->call('renew', $item->id)->assertSee(__('storefront.cart.swap', ['variant' => 'MP · ปกติ']));

    panelVariant($printing, CardCondition::Lp, qty: 2, priceSatang: 610000);

    $panel->call('swap', $item->id, $mp->id)
        ->assertSee(__('storefront.cart.swap', ['variant' => 'LP · ปกติ']))
        ->assertDontSee(__('storefront.cart.swap', ['variant' => 'MP · ปกติ']));

    expect(CartItem::query()->sole()->purchasable_id)->toBe($nm->id);
});

test('a swap that fails says so on the expired line', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1);
    $lp = panelVariant($printing, CardCondition::Lp, qty: 2, priceSatang: 90000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)->call('renew', $item->id);

    $this->mock(SwapCartItem::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new InsufficientStock(0)));

    $panel->call('swap', $item->id, $lp->id)
        ->assertSee(__('storefront.card.add_failed'))
        ->assertSee(__('storefront.cart.expired'))
        ->assertSee(__('storefront.cart.renew'));
});

test('a successful swap says so on the new line', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 680000);
    $lp = panelVariant($printing, CardCondition::Lp, qty: 3, priceSatang: 610000);
    $item = panelCart($nm, 1);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    $panel = Livewire::test(CartPanel::class)
        ->call('renew', $item->id)
        ->call('swap', $item->id, $lp->id)
        ->assertSee(__('storefront.cart.swapped', ['variant' => 'LP · ปกติ']));

    $panel->call('increment', CartItem::query()->sole()->id)
        ->assertDontSee(__('storefront.cart.swapped', ['variant' => 'LP · ปกติ']));
});

test('an expired line shows its static quantity, the renew button, and the lock-ended banner and summary note', function () {
    ['variant' => $variant] = panelShop(qty: 5);
    panelCart($variant, 2);
    $this->travel(16)->minutes();

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.lock_ended'))
        ->assertSee(__('storefront.cart.qty_static', ['n' => 2]))
        ->assertSee(__('storefront.cart.renew'))
        ->assertDontSee(__('storefront.cart.excluded', ['n' => 1]));
});

test('a held line with several copies shows its unit price, and an expired line keeps the summary note', function () {
    ['variant' => $held] = panelShop(qty: 5, priceSatang: 100000);
    ['variant' => $expiring] = panelShop(qty: 5, priceSatang: 50000, name: 'Second');
    $cart = Cart::factory()->create();
    panelCart($expiring, 1, $cart);
    $this->travel(16)->minutes();
    panelCart($held, 2, $cart);

    Livewire::test(CartPanel::class)
        ->assertSeeHtml('&times; 2')
        ->assertSee(__('storefront.cart.excluded', ['n' => 1]))
        ->assertSee(__('storefront.cart.lock', ['time' => '']));
});

test('the empty cart offers the filled search button with the cart glyph', function () {
    $html = Livewire::test(CartPanel::class)->assertSee(__('storefront.cart.search'))->html();

    expect($html)->toContain('M3.5 5h13')->toContain('bg-accent');
});

test('reserving when the release job deletes the cart\'s expired row mid-write still ends with one live hold', function () {
    $variant = panelShop(qty: 3)['variant'];
    $cart = Cart::factory()->create();
    StockReservation::factory()->expired()->create(['cart_id' => $cart->id, 'purchasable_id' => $variant->id, 'qty' => 1]);

    $released = false;

    DB::beforeExecuting(function (string $query) use (&$released): void {
        if ($released || ! preg_match('/^(update|delete from|insert into) ["`]?stock_reservations/i', $query)) {
            return;
        }

        $released = true;
        (new ReleaseExpiredReservations)->handle();
    });

    app(ReserveStock::class)->handle($cart, $variant, 2);

    expect($released)->toBeTrue()
        ->and(StockReservation::query()->where('cart_id', $cart->id)->count())->toBe(1)
        ->and(StockReservation::query()->where('cart_id', $cart->id)->live()->sole()->qty)->toBe(2);
});

test('the card page stepper at its limit does not carry the enabled colour either', function () {
    ['printing' => $printing, 'variant' => $variant] = panelShop(qty: 1);

    $html = Livewire::test(VariantTable::class, ['cardId' => $printing->card_id, 'printingId' => $printing->id])->html();

    preg_match_all('/<button[^>]*aria-label="(?:'.preg_quote(__('storefront.card.decrease'), '/').'|'.preg_quote(__('storefront.card.increase'), '/').')"[^>]*>/u', $html, $buttons);

    expect($buttons[0])->toHaveCount(2);

    foreach ($buttons[0] as $button) {
        expect($button)->toContain('text-faint')->not->toContain('text-ink');
    }
});

test('a substitute is never a variant the cart already has a line for', function () {
    ['printing' => $printing, 'variant' => $nm] = panelShop(qty: 1, priceSatang: 680000);
    $lp = panelVariant($printing, CardCondition::Lp, qty: 3, priceSatang: 610000);
    $mp = panelVariant($printing, CardCondition::Mp, qty: 3, priceSatang: 700000);
    $cart = Cart::factory()->create();
    $nmLine = panelCart($nm, 1, $cart);
    panelCart($lp, 2, $cart);
    $this->travel(16)->minutes();
    StockReservation::factory()->create(['purchasable_id' => $nm->id, 'qty' => 1]);

    // LP is cheaper and available, but it is already an (expired) line of this cart: the offer skips it.
    expect(app(RenewCartItem::class)->handle($nmLine)->substitute?->id)->toBe($mp->id);

    $mpLine = panelCart($mp, 1, $cart);
    $this->travel(16)->minutes();

    expect(app(RenewCartItem::class)->handle($nmLine->fresh())->substitute)->toBeNull()
        ->and($mpLine->fresh()->qty)->toBe(1);
});
