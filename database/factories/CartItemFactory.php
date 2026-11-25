<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * Define the model's default state. A line of a variant; it holds no stock until a StockReservation exists.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'purchasable_type' => 'variant',
            'purchasable_id' => Variant::factory(),
            'qty' => 1,
            'unit_price_satang' => fake()->numberBetween(10, 5000) * 100,
        ];
    }
}
