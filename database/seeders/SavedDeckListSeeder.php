<?php

namespace Database\Seeders;

use App\Actions\ParseDeckList;
use App\Enums\DeckLineKind;
use App\Models\Game;
use App\Models\SavedDeckList;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Seeder;

class SavedDeckListSeeder extends Seeder
{
    /**
     * One saved list for a demo customer (local only), written as a player would paste it: the code of a few seeded, stocked
     * printings of one game. Skipped when the catalogue has too few.
     */
    public function run(): void
    {
        // The demo customer has a known password, so it exists on a local machine only.
        if (! app()->isLocal()) {
            return;
        }

        $variants = Variant::query()
            ->purchasable()
            ->where('qty', '>=', 2)
            ->with(['printing.card', 'printing.set'])
            ->orderBy('id')
            ->get()
            ->unique('printing_id')
            ->groupBy(fn (Variant $variant): int => $variant->printing->card->game_id);

        // The first game with enough stocked printings, its list read by the same parser a check uses, so the saved game,
        // text and count agree.
        foreach (Game::query()->whereIn('id', $variants->keys())->orderBy('sort')->get() as $game) {
            $lines = $variants[$game->id]->take(5)
                ->map(fn (Variant $variant): string => '2x '.$variant->printing->set->code.' '.$variant->printing->number);

            $raw = $lines->implode("\n");
            $matched = app(ParseDeckList::class)->match($game, $raw)->toArray();

            if (count(array_filter($matched, fn (array $line): bool => $line['kind'] === DeckLineKind::Matched->value)) < 3) {
                continue;
            }

            $customer = User::query()->where('email', 'customer@example.com')->first()
                ?? User::factory()->create(['name' => 'Demo Customer', 'email' => 'customer@example.com']);

            SavedDeckList::query()->firstOrCreate(['user_id' => $customer->id, 'name' => 'Demo deck'], [
                'game_id' => $game->id,
                'raw_text' => $raw,
                'parsed' => SavedDeckList::slim($matched),
                'requested_cards' => SavedDeckList::requestedIn($matched),
            ]);

            return;
        }
    }
}
