<?php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $games = [
            ['slug' => 'pokemon', 'name_en' => 'Pokémon', 'name_th' => 'โปเกมอน', 'filters' => ['rarity', 'card_type', 'language']],
            ['slug' => 'one-piece', 'name_en' => 'One Piece', 'name_th' => 'วันพีซ', 'filters' => ['rarity', 'card_type', 'color', 'language']],
            ['slug' => 'yugioh', 'name_en' => 'Yu-Gi-Oh!', 'name_th' => 'ยูกิโอ', 'filters' => ['rarity', 'card_type', 'attribute', 'language']],
            ['slug' => 'magic', 'name_en' => 'Magic: The Gathering', 'name_th' => 'เมจิก: เดอะ แกเธอริง', 'filters' => ['rarity', 'card_type', 'color', 'language']],
            ['slug' => 'digimon', 'name_en' => 'Digimon', 'name_th' => 'ดิจิมอน', 'filters' => ['rarity', 'card_type', 'color', 'language']],
        ];

        foreach ($games as $index => $game) {
            Game::updateOrCreate(['slug' => $game['slug']], [
                ...$game,
                'sort' => $index + 1,
                'is_active' => true,
            ]);
        }
    }
}
