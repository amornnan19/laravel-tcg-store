<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Printing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printing>
 */
class PrintingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'set_id' => CardSet::factory(),
            'number' => (string) fake()->unique()->numberBetween(1, 999),
            'rarity_code' => fake()->randomElement(['c', 'u', 'r', 'rr', 'sar', 'sec']),
            'language' => Language::En,
            'has_foil' => false,
            'image_path' => null,
            'released_on' => null,
        ];
    }

    /**
     * Indicate that a foil finish exists for this printing.
     */
    public function withFoil(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_foil' => true,
        ]);
    }
}
