<?php

require_once __DIR__.'/graded.php';

use App\Models\CardSet;
use App\Models\Game;
use App\Models\SealedProduct;

/**
 * Shared by the sealed tests, which also use the cart and checkout helpers of tests/Support/graded.php.
 *
 * An active, priced, in-stock booster box with no game or set, unless attributes say otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function sealedProduct(array $attributes = []): SealedProduct
{
    return SealedProduct::factory()->create(['qty' => 5, 'price_satang' => 280000, ...$attributes]);
}

/**
 * A sealed product on a fresh active game and set.
 *
 * @param  array<string, mixed>  $attributes
 */
function sealedProductInSet(array $attributes = [], string $setCode = 'OP-05'): SealedProduct
{
    $game = Game::factory()->create();
    $set = CardSet::factory()->for($game)->create(['code' => $setCode, 'name_en' => 'Awakening of the New Era']);

    return sealedProduct(['game_id' => $game->id, 'set_id' => $set->id, ...$attributes]);
}
