<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name),
            'name_en' => ucwords($name),
            'name_th' => ucwords($name),
            'sort' => fake()->numberBetween(1, 20),
            'is_active' => true,
            'filters' => ['rarity', 'card_type', 'language'],
        ];
    }
}
