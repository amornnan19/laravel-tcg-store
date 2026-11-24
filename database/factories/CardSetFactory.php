<?php

namespace Database\Factories;

use App\Models\CardSet;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardSet>
 */
class CardSetFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('??##')),
            'name_en' => ucwords(fake()->unique()->words(3, true)),
            'name_th' => null,
            'released_on' => fake()->date(),
            'card_count' => fake()->numberBetween(60, 300),
            'sort' => 0,
        ];
    }
}
