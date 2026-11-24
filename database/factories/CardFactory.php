<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'name_en' => ucwords(fake()->unique()->words(2, true)),
            'name_ja' => null,
            'name_th' => null,
            'card_type' => fake()->randomElement(['pokemon', 'trainer', 'energy']),
            'traits' => [],
        ];
    }
}
