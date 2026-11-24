<?php

namespace Database\Seeders;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use RuntimeException;

class CatalogueSeeder extends Seeder
{
    /**
     * Plausible NM prices in whole baht by rarity code. The foil finish costs double.
     *
     * @var array<string, int>
     */
    private const PRICE_BAHT_BY_RARITY = [
        'c' => 10,
        'u' => 20,
        'r' => 50,
        'rr' => 150,
        'sar' => 1200,
        'sec' => 2500,
    ];

    private const FOIL_MULTIPLIER = 2;

    /**
     * Seed one real set per game from database/seeders/data/{game}.json. Never touches the network.
     */
    public function run(): void
    {
        fake()->seed(1);

        foreach (Game::query()->orderBy('sort')->get() as $game) {
            $path = database_path("seeders/data/{$game->slug}.json");

            if (! is_file($path)) {
                throw new RuntimeException("Missing seed data [{$path}]. Run catalogue:fetch-seed-data.");
            }

            $this->seedSet($game, json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function seedSet(Game $game, array $data): void
    {
        $set = CardSet::updateOrCreate(
            ['game_id' => $game->id, 'code' => $data['set']['code']],
            [
                'name_en' => $data['set']['name_en'],
                'name_th' => $data['set']['name_th'],
                'released_on' => $data['set']['released_on'],
                'card_count' => $data['set']['card_count'],
                'sort' => 0,
            ],
        );

        // A card is its name, type and traits, so Sabo the leader and Sabo the character stay apart.
        $cards = [];

        foreach ($data['cards'] as $row) {
            $language = Language::from($row['language']);

            if (Printing::where(['set_id' => $set->id, 'number' => $row['number'], 'language' => $language])->exists()) {
                continue;
            }

            $traits = $row['traits'] ?? [];
            $identity = json_encode([$row['name_en'], $row['card_type'] ?? null, $this->canonical($traits)]);

            $card = $cards[$identity] ??= Card::create([
                'game_id' => $game->id,
                'name_en' => $row['name_en'],
                'name_ja' => $row['name_ja'] ?? null,
                'card_type' => $row['card_type'] ?? null,
                'traits' => $traits,
            ]);

            $printing = Printing::create([
                'card_id' => $card->id,
                'set_id' => $set->id,
                'number' => $row['number'],
                'language' => $language,
                'rarity_code' => $row['rarity_code'],
                'has_foil' => $row['has_foil'],
                'released_on' => $set->released_on,
            ]);

            $this->seedVariant($printing, Finish::Normal);

            if ($printing->has_foil) {
                $this->seedVariant($printing, Finish::Foil);
            }
        }
    }

    /**
     * Sort associative keys recursively so equal traits encode identically. Lists keep their order.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function seedVariant(Printing $printing, Finish $finish): void
    {
        $baht = self::PRICE_BAHT_BY_RARITY[$printing->rarity_code] ?? self::PRICE_BAHT_BY_RARITY['r'];

        Variant::firstOrCreate(
            ['printing_id' => $printing->id, 'condition' => CardCondition::Nm, 'finish' => $finish],
            [
                'price_satang' => $baht * 100 * ($finish === Finish::Foil ? self::FOIL_MULTIPLIER : 1),
                'qty' => fake()->numberBetween(0, 8),
            ],
        );
    }
}
