<?php

namespace App\Support;

use App\Models\Cart;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The cart of the browser making this request, found through the token in its session. The row is created on the
 * first add and never on a page view, so a visitor who only browses leaves no cart behind.
 */
class CurrentCart
{
    public const SESSION_KEY = 'cart_token';

    /**
     * The session's cart token, or null before the first add. Reads the session only.
     */
    public function token(): ?string
    {
        $token = session(self::SESSION_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The visitor's cart, or null when the session has no token or its row is gone.
     */
    public function find(): ?Cart
    {
        $token = $this->token();

        return $token === null ? null : Cart::query()->where('token', $token)->first();
    }

    public function findOrCreate(): Cart
    {
        $cart = $this->find();

        if ($cart !== null) {
            return $cart;
        }

        // Two first adds in one session must not each create a cart: the loser's would hold stock nobody can see.
        return Cache::lock('cart-create:'.session()->getId(), 5)->block(5, function (): Cart {
            $cart = $this->find();

            if ($cart !== null) {
                return $cart;
            }

            $cart = Cart::query()->create(['token' => (string) Str::ulid()]);
            session()->put(self::SESSION_KEY, $cart->token);

            return $cart;
        });
    }
}
