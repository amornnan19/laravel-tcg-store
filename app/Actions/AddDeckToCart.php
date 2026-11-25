<?php

namespace App\Actions;

use App\Exceptions\DeckNotReserved;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\Cart;
use App\Models\Game;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts a checked deck list in the cart in one go: every variant is held, or none is. It trusts nothing it is given:
 * each variant must be purchasable and belong to the chosen game, and each quantity must be 1 to `deck.max_qty`.
 */
class AddDeckToCart
{
    public function __construct(private AddToCart $addToCart) {}

    /**
     * @param  array<int, int>  $variantQty  `variant id => copies`, lines on the same variant already summed.
     *
     * @throws DeckNotReserved with every variant that failed; the cart is then exactly as it was.
     */
    public function handle(Cart $cart, Game $game, array $variantQty): void
    {
        if ($variantQty === []) {
            throw new InvalidArgumentException('Add at least one variant.');
        }

        $ids = array_keys($variantQty);
        sort($ids);

        DB::transaction(function () use ($cart, $game, $variantQty, $ids): void {
            // The locking read comes first and in id order, so two carts adding overlapping decks cannot deadlock, and
            // on MySQL no earlier plain read fixes the snapshot before the reservation sums are taken.
            $locked = Variant::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $ofGame = Variant::query()
                ->whereIn('id', $ids)
                ->whereHas('printing.card', fn (Builder $card) => $card->where('game_id', $game->getKey()))
                ->pluck('id')
                ->flip();

            $failed = [];

            foreach ($ids as $id) {
                $qty = $variantQty[$id];
                $variant = $locked->get($id);

                if ($variant === null || ! $ofGame->has($id) || $qty < 1 || $qty > (int) config('deck.max_qty')) {
                    $failed[] = $id;

                    continue;
                }

                try {
                    $this->addToCart->handle($cart, $variant, $qty);
                } catch (InsufficientStock|NotPurchasable) {
                    $failed[] = $id;
                }
            }

            // Thrown from inside the closure: the whole transaction, the lines that did fit included, rolls back.
            if ($failed !== []) {
                throw new DeckNotReserved($failed);
            }
        });
    }
}
