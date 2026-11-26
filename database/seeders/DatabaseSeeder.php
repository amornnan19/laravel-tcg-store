<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isLocal()) {
            User::factory()->staff()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // Model events stay on: slugs and SKUs are generated in `creating` hooks.
        $this->call([
            GameSeeder::class,
            CatalogueSeeder::class,
            PricePointSeeder::class,
            OrderSeeder::class,
            GradedItemSeeder::class,
            SealedProductSeeder::class,
            SavedDeckListSeeder::class,
        ]);
    }
}
