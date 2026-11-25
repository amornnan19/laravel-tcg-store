<?php

use App\Actions\AddToCart;
use App\Contracts\Purchasable;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Livewire\Forms\CheckoutData;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\Game;
use App\Models\GradedItem;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared by the graded tests: a visible printing, a graded item on it, a variant and a checkout form.
 */
function gradedPrinting(string $name = 'Charizard ex'): Printing
{
    $card = Card::factory()->for(Game::factory())->create(['name_en' => $name]);

    return Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create(['language' => Language::En, 'number' => '215']);
}

/**
 * A listed graded item with photos, on a fresh visible printing unless one is given.
 *
 * @param  array<string, mixed>  $attributes
 */
function gradedItem(array $attributes = [], ?Printing $printing = null): GradedItem
{
    return GradedItem::factory()->for($printing ?? gradedPrinting())->create($attributes);
}

function gradedVariant(int $qty = 5, int $priceSatang = 100000): Variant
{
    return Variant::factory()->for(gradedPrinting('Mew'))->create([
        'condition' => CardCondition::Nm,
        'finish' => Finish::Normal,
        'qty' => $qty,
        'price_satang' => $priceSatang,
    ]);
}

/**
 * A cart that holds each purchasable once, as AddToCart leaves it.
 *
 * @param  list<Model&Purchasable>  $purchasables
 */
function gradedCart(array $purchasables): Cart
{
    $cart = Cart::factory()->create();

    foreach ($purchasables as $purchasable) {
        app(AddToCart::class)->handle($cart, $purchasable, 1);
    }

    return $cart;
}

function gradedCheckoutData(): CheckoutData
{
    $data = new CheckoutData(new CheckoutForm, 'form');

    foreach ([
        'name' => 'สมชาย ใจดี',
        'phone' => '0812345678',
        'email' => 'somchai@example.com',
        'line1' => '99/9 ซอยสุขุมวิท 1',
        'subdistrict' => 'คลองเตย',
        'district' => 'คลองเตย',
        'province' => 'กรุงเทพมหานคร',
        'postcode' => '10110',
        'courier' => 'flash',
        'protection' => false,
        'method' => 'promptpay',
    ] as $property => $value) {
        $data->{$property} = $value;
    }

    return $data;
}
