<?php

namespace Database\Factories;

use App\Enums\SealedKind;
use App\Models\SealedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A booster box that is for sale: active, priced and in stock, with no game or set until a test gives one.
 *
 * @extends Factory<SealedProduct>
 */
class SealedProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => null,
            'set_id' => null,
            'kind' => SealedKind::BoosterBox,
            'name_th' => null,
            'name_en' => ucwords(fake()->unique()->words(3, true)).' Booster Box',
            'sku' => strtoupper(fake()->unique()->bothify('BX-????-####')),
            'price_satang' => fake()->numberBetween(300, 4000) * 100,
            'qty' => fake()->numberBetween(2, 20),
            'image_path' => null,
            'description_th' => null,
            'is_active' => true,
            'is_preorder' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function preorder(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_preorder' => true,
        ]);
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'qty' => 0,
        ]);
    }

    public function unpriced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_satang' => null,
        ]);
    }
}
