<?php

use App\Actions\SubmitBuylist;
use App\Enums\BuylistPayout;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\User;
use App\Support\BuylistQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A buy price on a printing the storefront shows (live game, set and card). Pass `$for` to price another condition or
 * finish of a printing you already have.
 *
 * @param  array<string, mixed>  $price
 * @param  array<string, mixed>  $card
 * @param  array<string, mixed>  $printing
 */
function buylistRow(array $price = [], array $card = [], array $printing = [], ?Printing $for = null): BuylistPrice
{
    $game = Game::factory()->create();

    $for ??= Printing::factory()
        ->for(Card::factory()->for($game, 'game')->create($card))
        ->for(CardSet::factory()->for($game, 'game')->create(), 'set')
        ->create($printing);

    return BuylistPrice::factory()->for($for, 'printing')->create($price);
}

/**
 * Submits `price id => qty` the way the builder does: with the signature of what it would have shown.
 *
 * @param  array<int, int>  $quantities
 */
function submitBuylist(User $user, array $quantities, BuylistPayout $payout = BuylistPayout::Credit, ?string $signature = null): BuylistSubmission
{
    $prices = BuylistPrice::query()->whereKey(array_keys($quantities))->get();
    $signature ??= BuylistQuote::signature($prices->map(fn (BuylistPrice $price): array => ['price' => $price, 'qty' => $quantities[$price->getKey()]]));

    return app(SubmitBuylist::class)->handle(
        $user,
        $payout,
        collect($quantities)->map(fn (int $qty, int $id): array => ['buylist_price_id' => $id, 'qty' => $qty])->values()->all(),
        $signature,
    );
}
