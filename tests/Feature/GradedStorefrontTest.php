<?php

require_once __DIR__.'/../Support/graded.php';

use App\Actions\AddToCart;
use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Livewire\Storefront\GradedBuyBox;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

test('the graded page shows the photos, the grade, the price and the shop promises', function () {
    config(['shipping.graded_insurance_satang' => 20000]);
    $item = gradedItem(['price_satang' => 800000, 'grade_label' => 'Gem Mint', 'subgrades' => ['centering' => 9.5, 'corners' => 9]], gradedPrinting('Pikachu'));

    $this->get(route('graded.show', $item))
        ->assertOk()
        ->assertSee('Pikachu')
        ->assertSee('PSA 10')
        ->assertSee('Gem Mint')
        ->assertSee('฿ 8,000')
        ->assertSee('graded-photos/front.jpg', false)
        ->assertSee('graded-photos/back.jpg', false)
        ->assertSee('graded-photos/label.jpg', false)
        ->assertSee('Centering')
        ->assertSee('9.5')
        ->assertSee(__('storefront.graded.guarantee'))
        ->assertSee(__('storefront.graded.insured'))
        ->assertSee(__('storefront.graded.insured_amount', ['amount' => '฿ 200']))
        ->assertSee(__('storefront.graded.add'));
});

test('a withdrawn item is a 404, and so is one whose game the storefront hides', function () {
    $withdrawn = gradedItem(['status' => GradedStatus::Withdrawn]);
    $hidden = gradedItem();
    Game::query()->whereKey($hidden->printing->card->game_id)->update(['is_active' => false]);

    $this->get(route('graded.show', $withdrawn))->assertNotFound();
    $this->get(route('graded.show', $hidden))->assertNotFound();
});

test('a sold item stays up as sold with no add button', function () {
    $item = gradedItem(['status' => GradedStatus::Sold]);

    $this->get(route('graded.show', $item))
        ->assertOk()
        ->assertSee(__('storefront.graded.sold'))
        ->assertDontSee('wire:click="add"', false)
        ->assertDontSee(__('storefront.graded.add'));
});

test('an item reserved by another cart says so and offers no add button', function () {
    $item = gradedItem();
    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);

    $this->get(route('graded.show', $item))
        ->assertOk()
        ->assertSee(__('storefront.graded.taken'))
        ->assertDontSee('wire:click="add"', false);
});

test('a reserved item whose hold ran out can be taken again', function () {
    $item = gradedItem();
    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    $this->get(route('graded.show', $item))->assertSee('wire:click="add"', false)->assertDontSee(__('storefront.graded.taken'));
});

test('adding puts the item in the visitor cart, reserved, and the box then says it is in the cart', function () {
    $item = gradedItem();

    $box = Livewire::test(GradedBuyBox::class, ['gradedItemId' => $item->id])
        ->assertSee(__('storefront.graded.add'))
        ->call('add')
        ->assertDispatched('cart-updated')
        ->assertSee(__('storefront.graded.in_cart', ['minutes' => 15]))
        ->assertDontSee('wire:click="add"', false);

    expect($item->fresh()->status)->toBe(GradedStatus::Reserved)
        ->and(CartItem::query()->where('purchasable_type', 'graded')->where('purchasable_id', $item->id)->count())->toBe(1);

    $box->call('add');

    expect(StockReservation::query()->count())->toBe(1);
});

test('adding an item another cart took between the render and the click changes nothing and shows why', function () {
    $item = gradedItem();
    $box = Livewire::test(GradedBuyBox::class, ['gradedItemId' => $item->id]);

    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);

    $box->call('add')->assertSee(__('storefront.graded.taken'));

    expect(CartItem::query()->count())->toBe(1);
});

test('the certificate shows the number, and a PSA link only for PSA', function () {
    $psa = gradedItem(['cert_number' => '12345678']);
    $bgs = gradedItem(['cert_number' => '0011223344', 'grader' => Grader::Bgs]);

    $this->get(route('graded.show', $psa))
        ->assertSee('12345678')
        ->assertSee('https://www.psacard.com/cert/12345678', false)
        ->assertSee(__('storefront.graded.verify', ['grader' => 'PSA']))
        ->assertSee(__('storefront.graded.new_tab'));

    $this->get(route('graded.show', $bgs))
        ->assertSee('0011223344')
        ->assertDontSee('psacard.com', false)
        ->assertDontSee(__('storefront.graded.verify', ['grader' => 'BGS']));
});

test('the card page lists only the listed graded items of the card, and hides the section when there are none', function () {
    $printing = gradedPrinting('Charizard ex');
    $listed = gradedItem(['grade' => 9], $printing);
    gradedItem(['grade' => 8, 'status' => GradedStatus::Reserved], $printing);
    gradedItem(['grade' => 7, 'status' => GradedStatus::Sold], $printing);
    gradedItem(['grade' => 6, 'status' => GradedStatus::Withdrawn], $printing);

    $this->get(route('card.show', ['card' => $printing->card, 'p' => $printing->id]))
        ->assertOk()
        ->assertSee(__('storefront.graded.section'))
        ->assertSee(route('graded.show', $listed))
        ->assertSee('PSA 9')
        ->assertDontSee('PSA 8')
        ->assertDontSee('PSA 7')
        ->assertDontSee('PSA 6');

    $other = gradedPrinting('Mew');

    $this->get(route('card.show', ['card' => $other->card, 'p' => $other->id]))
        ->assertOk()
        ->assertDontSee(__('storefront.graded.section'));
});

test('the graded page leads with the slab label, then the guarantee, then the price, and has the lightbox controls', function () {
    $item = gradedItem(['grade_label' => 'Gem Mint', 'subgrades' => ['centering' => 9.5]]);

    $this->get(route('graded.show', $item))
        ->assertOk()
        ->assertSeeInOrder(['PSA 10', 'Gem Mint', __('storefront.graded.cert'), __('storefront.graded.subgrades'), __('storefront.graded.guarantee'), '฿'])
        ->assertSee(__('storefront.graded.tap_to_zoom'))
        ->assertSee(__('storefront.graded.prev'))
        ->assertSee(__('storefront.graded.next'))
        ->assertSee(__('storefront.graded.full_size'))
        ->assertSee(__('storefront.graded.fit'))
        ->assertDontSee('&lsaquo;', false)
        ->assertSee('bg-bg/95', false)
        ->assertDontSee('รอร้านกรอกเลขใบรับรอง');
});

test('a taken item polls, says when it returns and links to the ungraded card', function () {
    $item = gradedItem();
    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);

    $this->get(route('graded.show', $item))
        ->assertSee(__('storefront.graded.taken'))
        ->assertSee(__('storefront.graded.taken_hint', ['minutes' => 15]))
        ->assertSee(__('storefront.graded.view_ungraded'))
        ->assertSee('wire:poll.30s', false);
});

test('an item that can be bought does not poll', function () {
    $item = gradedItem();

    $this->get(route('graded.show', $item))->assertDontSee('wire:poll', false);
});

test('a sold item fades the price, hides the insurance and lock lines and links to the ungraded card', function () {
    $item = gradedItem(['status' => GradedStatus::Sold]);

    $this->get(route('graded.show', $item))
        ->assertSee(__('storefront.graded.view_ungraded'))
        ->assertSee('text-faint', false)
        ->assertDontSee(__('storefront.graded.insured'))
        ->assertDontSee(__('storefront.graded.one_of_a_kind', ['minutes' => 15]));
});

test('the card page links to the graded section with the count and the lowest price', function () {
    $printing = gradedPrinting();
    gradedItem(['price_satang' => 900000], $printing);
    gradedItem(['price_satang' => 450000], $printing);

    $this->get(route('card.show', ['card' => $printing->card, 'p' => $printing->id]))
        ->assertSee(__('storefront.graded.card_link', ['n' => 2, 'price' => '฿ 4,500']))
        ->assertSee('href="#graded-heading"', false);

    $other = gradedPrinting('Mew');

    $this->get(route('card.show', ['card' => $other->card, 'p' => $other->id]))->assertDontSee('#graded-heading', false);
});

test('the graded tile shows the real front photo, the grade, the label and the photos pill', function () {
    $printing = gradedPrinting();
    gradedItem(['grade_label' => 'Gem Mint'], $printing);

    $this->get(route('card.show', ['card' => $printing->card, 'p' => $printing->id]))
        ->assertSee('graded-photos/front.jpg', false)
        ->assertSee('Gem Mint')
        ->assertSee(__('storefront.graded.tile_photos'))
        ->assertSee('rounded-full', false);
});

test('the three thumbs swap the main photo and only the main photo opens the lightbox', function () {
    $item = gradedItem();

    $html = $this->get(route('graded.show', $item))->assertOk()->getContent();

    foreach ([0, 1, 2] as $index) {
        expect($html)->toContain('x-on:click="current = '.$index.'"');
    }

    expect($html)
        ->toContain('x-on:click="show(current)"')
        ->not->toContain('x-on:click="show(0)"')
        ->not->toContain('x-on:click="show(1)"')
        ->toContain(':aria-current="current === 1 ? \'true\' : null"')
        ->toContain('size-20')
        ->toContain('focus-visible:outline-accent');
});

test('the gallery is capped so the slab and the button stay near the top, and the buy column sticks on desktop', function () {
    $item = gradedItem();

    $this->get(route('graded.show', $item))
        ->assertSee('max-h-[55vh]', false)
        ->assertSee('lg:max-h-[calc(100vh-14rem)]', false)
        ->assertSee('lg:sticky lg:top-24 lg:self-start', false)
        ->assertSeeInOrder(['order-1', 'order-2', 'order-3'], false);
});

test('subgrades read to one decimal', function () {
    $item = gradedItem(['grader' => Grader::Bgs, 'grade' => 9.5, 'subgrades' => ['centering' => 9, 'corners' => 9.5]]);

    $this->get(route('graded.show', $item))
        ->assertSeeInOrder(['Centering', '9.0', 'Corners', '9.5'])
        ->assertSee('BGS 9.5');
});

test('a taken or sold item links to the other graded copies of the card only when there is one for sale', function (GradedStatus $state) {
    $printing = gradedPrinting();
    $item = gradedItem(['status' => $state], $printing);

    if ($state === GradedStatus::Reserved) {
        app(AddToCart::class)->handle(Cart::factory()->create(), $item->fresh()->forceFill(['status' => GradedStatus::Listed]), 1);
    }

    $this->get(route('graded.show', $item))->assertDontSee(__('storefront.graded.other_graded'));

    gradedItem(['grade' => 9], $printing);

    $this->get(route('graded.show', $item))
        ->assertSee(__('storefront.graded.other_graded'))
        ->assertSee(route('card.show', ['card' => $printing->card, 'p' => $printing->id]).'#graded-heading', false);
})->with([GradedStatus::Reserved, GradedStatus::Sold]);
