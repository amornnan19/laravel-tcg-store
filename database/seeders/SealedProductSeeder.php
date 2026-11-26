<?php

namespace Database\Seeders;

use App\Enums\SealedKind;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\SealedProduct;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

class SealedProductSeeder extends Seeder
{
    use SeedsDemoData;

    /**
     * Six sealed products across the four kinds (demo data), with no image: the storefront draws its neutral block.
     * Boxes, packs and decks sit on seeded sets, the accessory on a game only. Prices are plausible demo values,
     * never a real quote.
     */
    public function run(): void
    {
        if (! $this->shouldSeedDemoData() || SealedProduct::query()->where('sku', 'like', 'DEMO-%')->where('is_preorder', false)->exists()) {
            return;
        }

        $sets = CardSet::query()->searchVisible()->orderBy('id')->limit(3)->get();
        $game = Game::query()->where('is_active', true)->orderBy('id')->first();

        if ($sets->isEmpty() || $game === null) {
            return;
        }

        // [kind, set index or null, name, price in baht, stock]
        $specs = [
            [SealedKind::BoosterBox, 0, 'Booster Box', 2800, 12],
            [SealedKind::BoosterBox, 1, 'Booster Box', 3200, 6],
            [SealedKind::BoosterPack, 0, 'Booster Pack', 120, 80],
            [SealedKind::BoosterPack, 2, 'Booster Pack', 135, 0],
            [SealedKind::StarterDeck, 1, 'Starter Deck', 590, 15],
            [SealedKind::Accessory, null, 'Card Sleeves 60 pcs', 189, 40],
        ];

        foreach ($specs as $index => [$kind, $setIndex, $name, $baht, $qty]) {
            $set = $setIndex === null ? null : ($sets[$setIndex] ?? $sets->first());

            SealedProduct::query()->create([
                'game_id' => $set?->game_id ?? $game->id,
                'set_id' => $set?->id,
                'kind' => $kind,
                'name_en' => $set === null ? $name : $set->name_en.' '.$name,
                'sku' => 'DEMO-'.strtoupper($kind->value).'-'.($index + 1),
                'price_satang' => $baht * 100,
                'qty' => $qty,
                'description_th' => 'สินค้าตัวอย่างสำหรับทดสอบหน้าร้าน',
                'is_active' => true,
                'is_preorder' => false,
            ]);
        }
    }
}
