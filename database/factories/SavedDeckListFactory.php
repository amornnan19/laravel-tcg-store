<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\SavedDeckList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedDeckList>
 */
class SavedDeckListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'game_id' => Game::factory(),
            'name' => fake()->words(2, true),
            'raw_text' => "4x OP05-119\n2x OP05-120",
            'parsed' => [],
            'requested_cards' => 6,
        ];
    }
}
