<?php

use App\Enums\CardCondition;
use App\Filament\Widgets\RestockAlertsFiredWidget;
use App\Livewire\Storefront\AlertToggle;
use App\Livewire\Storefront\VariantTable;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\PriceAlert;
use App\Models\Printing;
use App\Models\RestockAlert;
use App\Models\User;
use App\Models\Variant;
use App\Models\WishlistItem;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function shelfVariant(int $qty = 0, ?int $priceSatang = 100000, string $name = 'Charizard ex'): Variant
{
    $card = Card::factory()->for(Game::factory())->create(['name_en' => $name]);
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create();

    return Variant::factory()->for($printing)->create(['qty' => $qty, 'price_satang' => $priceSatang]);
}

function shelfTable(Variant $variant)
{
    return Livewire::test(VariantTable::class, ['cardId' => $variant->printing->card_id, 'printingId' => $variant->printing_id]);
}

test('a guest who presses the heart is sent to log in and brought back to the card', function () {
    $printing = shelfVariant()->printing;

    Livewire::test(AlertToggle::class, ['printingId' => $printing->id])
        ->call('toggle')
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('card.show', ['card' => $printing->card, 'p' => $printing->id]));
});

test('a signed-in customer toggles the heart on and off', function () {
    $user = User::factory()->create();
    $printing = shelfVariant()->printing;

    $heart = Livewire::actingAs($user)->test(AlertToggle::class, ['printingId' => $printing->id])
        ->assertSeeHtml('aria-pressed="false"')
        ->call('toggle')
        ->assertSeeHtml('aria-pressed="true"');

    expect($user->wishlistItems()->where('printing_id', $printing->id)->exists())->toBeTrue();

    $heart->call('toggle')->assertSeeHtml('aria-pressed="false"');

    expect($user->wishlistItems()->count())->toBe(0);
});

test('the card page shows the heart', function () {
    $variant = shelfVariant(qty: 2);

    $this->get(route('card.show', ['card' => $variant->printing->card, 'p' => $variant->printing->id]))
        ->assertOk()
        ->assertSee(__('storefront.alerts.wishlist_add'));
});

test('a sold-out row offers the restock alert, which a signed-in customer sets and cancels', function () {
    $user = User::factory()->create();
    $variant = shelfVariant(qty: 0);

    $table = Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $variant->printing->card_id, 'printingId' => $variant->printing_id])
        ->assertSee(__('storefront.alerts.notify_restock'))
        ->call('notifyRestock', $variant->id)
        ->assertSee(__('storefront.alerts.restock_set'))
        ->assertSee(__('storefront.alerts.restock_cancel'))
        ->assertDontSeeHtml('wire:click="notifyRestock(');

    expect(RestockAlert::query()->where('user_id', $user->id)->where('variant_id', $variant->id)->where('is_active', true)->exists())->toBeTrue();

    $table->call('cancelRestock', $variant->id)->assertSeeHtml('wire:click="notifyRestock(')->assertSee(__('storefront.alerts.restock_hint_email'));

    expect(RestockAlert::query()->count())->toBe(0);
});

test('a restock alert on a variant that is in stock is refused, and the refusal shows under that row', function () {
    $user = User::factory()->create();
    $variant = shelfVariant(qty: 3);

    Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $variant->printing->card_id, 'printingId' => $variant->printing_id])
        ->call('notifyRestock', $variant->id)
        ->assertSet('restockNotices', [$variant->id => __('storefront.alerts.refused.unavailable')])
        ->assertSee(__('storefront.alerts.refused.unavailable'));

    expect(RestockAlert::query()->count())->toBe(0);
});

test('a restock alert for a customer with no channel shows the refusal', function () {
    $user = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);
    $variant = shelfVariant(qty: 0);

    Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $variant->printing->card_id, 'printingId' => $variant->printing_id])
        ->call('notifyRestock', $variant->id)
        ->assertSet('restockNotices', [$variant->id => __('storefront.alerts.refused.no_channel_email')]);
});

test('a restock alert cannot be set on a variant of another card', function () {
    $user = User::factory()->create();
    $shown = shelfVariant(qty: 0);
    $elsewhere = shelfVariant(qty: 0, name: 'Pikachu');

    Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $shown->printing->card_id, 'printingId' => $shown->printing_id])
        ->call('notifyRestock', $elsewhere->id);

    expect(RestockAlert::query()->count())->toBe(0);
});

test('a guest who asks for a restock alert or a price watch is sent to log in', function (string $action) {
    $variant = shelfVariant(qty: 3);

    shelfTable($variant)->call($action, $variant->id)->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('card.show', ['card' => $variant->printing->card, 'p' => $variant->printing_id]));
})->with(['notifyRestock', 'openPriceWatch']);

test('the price watch opens a form, refuses a price already reached, then sets and cancels', function () {
    $user = User::factory()->create();
    $variant = shelfVariant(qty: 3, priceSatang: 100000);

    $table = Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $variant->printing->card_id, 'printingId' => $variant->printing_id])
        ->assertSee(__('storefront.alerts.price_watch'))
        ->call('openPriceWatch')
        ->assertSet('watching', true)
        ->assertSee(__('storefront.alerts.price_target_label', ['variant' => 'NM · EN · '.__('enums.finish.normal')]))
        ->set('priceTarget', '1,500')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', __('storefront.alerts.refused.price_reached', ['price' => Money::fromSatang(100000)->format()]))
        ->assertSet('priceTarget', '1,500')
        ->set('priceTarget', 'abc')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', __('storefront.alerts.refused.invalid_target'))
        ->set('priceTarget', '800.50')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', null)
        ->assertSet('watching', false)
        ->assertSee(__('storefront.alerts.price_watch_set', ['variant' => 'NM · EN · '.__('enums.finish.normal'), 'target' => Money::fromSatang(80050)->format()]))
        ->assertSee(__('storefront.alerts.price_watch_marker', ['target' => Money::fromSatang(80050)->format()]))
        ->assertSee(__('storefront.alerts.price_watch_cancel'));

    expect(PriceAlert::query()->sole()->target_satang)->toBe(80050);

    $table->call('cancelPriceWatch', $variant->id)->assertSee(__('storefront.alerts.price_watch'));

    expect(PriceAlert::query()->count())->toBe(0);
});

test('the price watch is only for the row the customer chose', function () {
    $user = User::factory()->create();
    $variant = shelfVariant(qty: 3);
    $soldOut = Variant::factory()->for($variant->printing)->create(['qty' => 0, 'price_satang' => 50000, 'condition' => CardCondition::Lp]);

    Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $variant->printing->card_id, 'printingId' => $variant->printing_id])
        ->set('variantId', $soldOut->id)
        ->set('priceTarget', '10')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', __('storefront.alerts.refused.unavailable'));

    expect(PriceAlert::query()->count())->toBe(0);
});

test('the account page lists the customer\'s wishlist and alerts, never another customer\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = shelfVariant(qty: 2, name: 'Mine Wish');
    $alertCard = shelfVariant(qty: 0, name: 'Mine Alert');
    $theirs = shelfVariant(qty: 2, name: 'Theirs');
    WishlistItem::factory()->create(['user_id' => $user->id, 'printing_id' => $mine->printing_id]);
    RestockAlert::factory()->create(['user_id' => $user->id, 'variant_id' => $alertCard->id]);
    PriceAlert::factory()->create(['user_id' => $user->id, 'variant_id' => $mine->id, 'target_satang' => 50000]);
    WishlistItem::factory()->create(['user_id' => $other->id, 'printing_id' => $theirs->printing_id]);
    RestockAlert::factory()->create(['user_id' => $other->id, 'variant_id' => $theirs->id]);

    $this->actingAs($user)->get(route('account'))
        ->assertOk()
        ->assertSee('Mine Wish')
        ->assertSee('Mine Alert')
        ->assertSee(__('storefront.account.alert_price_waiting'))
        ->assertSee(__('storefront.account.alert_restock_waiting'))
        ->assertDontSee('Theirs');
});

test('the account page shows the empty wishlist copy', function () {
    $this->actingAs(User::factory()->create())->get(route('account'))
        ->assertOk()
        ->assertSee(__('storefront.account.wishlist_empty'))
        ->assertSee(__('storefront.account.alerts_empty'));
});

test('the wishlist hides a printing that left the shop', function () {
    $user = User::factory()->create();
    $variant = shelfVariant(qty: 2, name: 'Gone Card');
    WishlistItem::factory()->create(['user_id' => $user->id, 'printing_id' => $variant->printing_id]);
    $variant->printing->delete();

    $this->actingAs($user)->get(route('account'))->assertOk()->assertDontSee('Gone Card');
});

test('removing a wishlist entry or an alert works for the owner and is a 404 for anyone else', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $wish = WishlistItem::factory()->create(['user_id' => $owner->id]);
    $price = PriceAlert::factory()->create(['user_id' => $owner->id]);
    $restock = RestockAlert::factory()->create(['user_id' => $owner->id]);
    $routes = [
        route('account.wishlist.destroy', $wish),
        route('account.alerts.price.destroy', $price),
        route('account.alerts.restock.destroy', $restock),
    ];

    foreach ($routes as $url) {
        $this->actingAs($stranger)->delete($url)->assertNotFound();
    }

    expect(WishlistItem::query()->count() + PriceAlert::query()->count() + RestockAlert::query()->count())->toBe(3);

    foreach ($routes as $url) {
        $this->actingAs($owner)->delete($url)->assertRedirect(route('account'));
    }

    expect(WishlistItem::query()->count() + PriceAlert::query()->count() + RestockAlert::query()->count())->toBe(0);
});

test('a guest cannot reach the removal routes', function () {
    $this->delete(route('account.wishlist.destroy', WishlistItem::factory()->create()))->assertRedirect(route('login'));
});

test('the restock widget lists the variants that fired in the last day, most alerts first, and only for staff', function () {
    $busy = shelfVariant(qty: 5, name: 'Busy Card');
    $quiet = shelfVariant(qty: 5, name: 'Quiet Card');
    $old = shelfVariant(qty: 5, name: 'Old Card');
    RestockAlert::factory()->count(2)->notified()->create(['variant_id' => $busy->id]);
    RestockAlert::factory()->notified()->create(['variant_id' => $quiet->id]);
    RestockAlert::factory()->create(['variant_id' => $old->id, 'is_active' => false, 'notified_at' => now()->subDays(2)]);

    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Livewire::test(RestockAlertsFiredWidget::class)->assertOk()
        ->assertSee(__('admin.widgets.restock_alerts_fired'))
        ->assertSee(__('admin.widgets.restock_alerts_count', ['n' => 3]))
        ->assertSeeInOrder(['Busy Card', 'Quiet Card'])
        ->assertDontSee('Old Card');

    expect(RestockAlertsFiredWidget::canView())->toBeTrue();

    $this->actingAs(User::factory()->create());

    expect(RestockAlertsFiredWidget::canView())->toBeFalse();
});

test('a price-watch error is shown inside its form, linked to the input, and a restock refusal never touches it', function () {
    $user = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);
    $inStock = shelfVariant(qty: 3, priceSatang: 100000);
    $soldOut = Variant::factory()->for($inStock->printing)->create(['qty' => 0, 'price_satang' => 50000, 'condition' => CardCondition::Lp]);

    $table = Livewire::actingAs($user->forceFill(['email' => 'user@example.com']))->test(VariantTable::class, ['cardId' => $inStock->printing->card_id, 'printingId' => $inStock->printing_id])
        ->call('openPriceWatch')
        ->assertDontSeeHtml('aria-invalid="true"')
        ->set('priceTarget', 'abc')
        ->call('setPriceWatch')
        ->assertSeeHtml('id="price-target-error"')
        ->assertSeeHtml('aria-describedby="price-target-error price-target-hint"')
        ->assertSeeHtml('aria-invalid="true"')
        ->assertSee(__('storefront.alerts.refused.invalid_target'));

    // A restock action closes the price form and clears its error: nothing of it is left on the page.
    $user->forceFill(['email' => null])->save();
    $table->call('notifyRestock', $soldOut->id)
        ->assertSet('watching', false)
        ->assertSet('priceWatchError', null)
        ->assertSet('restockNotices', [$soldOut->id => __('storefront.alerts.refused.no_channel_email')])
        ->assertDontSeeHtml('aria-invalid="true"')
        ->assertDontSeeHtml('id="price-target-error"');
});

test('a restock action closes an open price form, and its refusal renders once, in that row\'s footer only', function () {
    $user = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);
    $inStock = shelfVariant(qty: 3, priceSatang: 100000);
    $soldOut = Variant::factory()->for($inStock->printing)->create(['qty' => 0, 'price_satang' => 50000, 'condition' => CardCondition::Lp]);
    $other = Variant::factory()->for($inStock->printing)->create(['qty' => 0, 'price_satang' => 40000, 'condition' => CardCondition::Mp]);

    $html = Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $inStock->printing->card_id, 'printingId' => $inStock->printing_id])
        ->call('openPriceWatch')
        ->assertSet('watching', true)
        ->call('notifyRestock', $soldOut->id)
        ->assertSet('watching', false)
        ->html();

    $refusal = __('storefront.alerts.refused.no_channel_email');
    $footer = fn (Variant $variant): string => str($html)->after('id="restock-action-'.$variant->id.'"')->before('</div>')->toString();

    expect(substr_count($html, $refusal))->toBe(1)
        ->and($footer($soldOut))->toContain($refusal)
        ->and($footer($other))->not->toContain($refusal);
});

test('a guest who presses the heart has the printing added once they log in', function () {
    $user = User::factory()->create();
    $printing = shelfVariant(qty: 2)->printing;

    Livewire::test(AlertToggle::class, ['printingId' => $printing->id])->call('toggle');

    expect(session('wishlist.pending'))->toBe($printing->id);
    $this->get(route('login'))->assertOk()->assertSee(__('storefront.alerts.wishlist_login_note'));

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    expect($user->wishlistItems()->where('printing_id', $printing->id)->count())->toBe(1)
        ->and(session()->has('wishlist.pending'))->toBeFalse();
});

test('the pending wishlist add is consumed once: a removed item is not re-added by visiting the card', function () {
    $user = User::factory()->create();
    $printing = shelfVariant(qty: 2)->printing;
    $card = route('card.show', ['card' => $printing->card, 'p' => $printing->id]);

    $this->withSession(['wishlist.pending' => $printing->id])->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    expect($user->wishlistItems()->count())->toBe(1);

    $user->wishlistItems()->delete();
    $this->get($card)->assertOk();
    $this->get($card)->assertOk();

    expect($user->wishlistItems()->count())->toBe(0);
});

test('mounting the heart never toggles anything, whatever the session holds', function () {
    $user = User::factory()->create();
    $printing = shelfVariant(qty: 2)->printing;
    WishlistItem::factory()->create(['user_id' => $user->id, 'printing_id' => $printing->id]);
    session()->put('wishlist.pending', $printing->id);

    Livewire::actingAs($user)->test(AlertToggle::class, ['printingId' => $printing->id])->assertSeeHtml('aria-pressed="true"');
    Livewire::actingAs($user)->test(AlertToggle::class, ['printingId' => $printing->id])->assertSeeHtml('aria-pressed="true"');

    expect($user->wishlistItems()->count())->toBe(1)->and(session('wishlist.pending'))->toBe($printing->id);
});

test('logging in with a pending add for a printing already listed or no longer shown leaves the list as it is', function () {
    $user = User::factory()->create();
    $listed = shelfVariant(qty: 2)->printing;
    $gone = shelfVariant(qty: 2, name: 'Gone')->printing;
    WishlistItem::factory()->create(['user_id' => $user->id, 'printing_id' => $listed->id]);

    $this->withSession(['wishlist.pending' => $listed->id])->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    expect($user->wishlistItems()->count())->toBe(1);

    $this->post(route('logout'));
    $gone->delete();
    $this->withSession(['wishlist.pending' => $gone->id])->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    expect($user->wishlistItems()->count())->toBe(1)->and(session()->has('wishlist.pending'))->toBeFalse();
});

test('the login page has no wishlist note without a pending add', function () {
    $this->get(route('login'))->assertDontSee(__('storefront.alerts.wishlist_login_note'));
});

test('the price watch acts on the row the form names when the chosen row sells out', function () {
    $user = User::factory()->create();
    $chosen = shelfVariant(qty: 1, priceSatang: 50000);
    $fallback = Variant::factory()->for($chosen->printing)->create(['qty' => 4, 'price_satang' => 90000, 'condition' => CardCondition::Lp]);

    $table = Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $chosen->printing->card_id, 'printingId' => $chosen->printing_id])
        ->assertSet('variantId', $chosen->id);

    Variant::query()->whereKey($chosen->id)->update(['qty' => 0]);

    $table->call('openPriceWatch')
        ->assertSet('variantId', $fallback->id)
        ->set('priceTarget', '700')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', null);

    expect(PriceAlert::query()->sole()->variant_id)->toBe($fallback->id);
});

test('a price watch refused because the row sold out re-points to a row that can be bought', function () {
    $user = User::factory()->create();
    $chosen = shelfVariant(qty: 1, priceSatang: 50000);
    $fallback = Variant::factory()->for($chosen->printing)->create(['qty' => 4, 'price_satang' => 90000, 'condition' => CardCondition::Lp]);

    $table = Livewire::actingAs($user)->test(VariantTable::class, ['cardId' => $chosen->printing->card_id, 'printingId' => $chosen->printing_id])
        ->call('openPriceWatch');

    Variant::query()->whereKey($chosen->id)->update(['qty' => 0]);

    $table->set('priceTarget', '700')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', __('storefront.alerts.refused.unavailable'))
        ->assertSet('variantId', $fallback->id)
        ->set('priceTarget', '700')
        ->call('setPriceWatch')
        ->assertSet('priceWatchError', null);

    expect(PriceAlert::query()->sole()->variant_id)->toBe($fallback->id);
});

test('a fired price alert on the account page shows the card link and a delete button', function () {
    $user = User::factory()->create();
    $variant = shelfVariant(qty: 2, priceSatang: 70000, name: 'Fired Card');
    PriceAlert::factory()->fired()->create(['user_id' => $user->id, 'variant_id' => $variant->id, 'target_satang' => 80000]);

    $this->actingAs($user)->get(route('account'))
        ->assertOk()
        ->assertSee(__('storefront.account.alert_view_card'))
        ->assertSee(__('storefront.account.alert_delete'))
        ->assertDontSee(__('storefront.account.alert_price_waiting'));
});
