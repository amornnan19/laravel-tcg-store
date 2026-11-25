<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * Define the model's default state: a live hold on a variant for the configured lock time.
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
            'expires_at' => now()->addMinutes(config('shop.reservation_minutes')),
        ];
    }

    /**
     * An expired hold: it holds nothing, though the release job may not have deleted it yet.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
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
