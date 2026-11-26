<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use SeedsDemoData;

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
        $this->call([GameSeeder::class, CatalogueSeeder::class]);

        // Postgres plans from table statistics, and a table just filled has none until autovacuum gets to it: the demo
        // seeders' joins over the catalogue then run as nested loops that take minutes. SQLite and MySQL need nothing.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('analyze');
        }

        if ($this->shouldSeedDemoData()) {
            $this->call([
                PricePointSeeder::class,
                OrderSeeder::class,
                GradedItemSeeder::class,
                SealedProductSeeder::class,
                PreorderSeeder::class,
                BuylistPriceSeeder::class,
            ]);
        }

        // These need the known-password customer, so they never run outside a local environment.
        if (app()->isLocal()) {
            $this->call([SavedDeckListSeeder::class, StoreCreditSeeder::class]);
        }
    }
}
