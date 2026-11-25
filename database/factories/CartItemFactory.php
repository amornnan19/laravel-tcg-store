<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

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
            'purchasable_type' => fn (array $attributes): string => (new Variant)->getMorphClass(),
            'purchasable_id' => Variant::factory(),
            'qty' => 1,
            'unit_price_satang' => fake()->numberBetween(10, 5000) * 100,
        ];
    }

    /**
     * A line or hold on this purchasable instead of a fresh variant.
     */
    public function forPurchasable(Model $purchasable): static
    {
        return $this->state(fn (array $attributes): array => [
            'purchasable_type' => $purchasable->getMorphClass(),
            'purchasable_id' => $purchasable->getKey(),
        ]);
    }
}
