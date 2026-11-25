<?php

namespace Database\Seeders;

use App\Enums\CardCondition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample 90-day price history, so the card page's chart shows in a demo. It is not real sales data: the page
 * says so while `shop.sample_price_history` is on. Only NM variants of the rarities collectors watch get one.
 */
class PricePointSeeder extends Seeder
{
    private const DAYS = 90;

    private const RARITIES = ['rr', 'sar', 'sec'];

    /** The walk stays within this share of the current price, either way. */
    private const BAND = 0.15;

    private const STEP = 0.03;

    public function run(): void
    {
        fake()->seed(2);

        $rows = [];
        $stamp = now()->toDateTimeString();
        $dates = array_map(fn (int $day): string => today()->subDays($day)->toDateString(), range(0, self::DAYS - 1));

        DB::table('variants')
            ->join('printings', 'printings.id', '=', 'variants.printing_id')
            ->where('variants.condition', CardCondition::Nm->value)
            ->whereNotNull('variants.price_satang')
            ->whereIn('printings.rarity_code', self::RARITIES)
            ->orderBy('variants.id')
            ->select(['variants.id', 'variants.price_satang'])
            ->lazyById(500, 'variants.id', 'id')
            ->each(function (object $variant) use (&$rows, $dates, $stamp): void {
                $current = (int) $variant->price_satang;
                $offset = 0.0;

                // Walk back from today, so the newest row is the price the shop asks now.
                for ($day = 0; $day < self::DAYS; $day++) {
                    $average = (int) round($current * (1 + $offset));

                    $rows[] = [
                        'variant_id' => $variant->id,
                        'on_date' => $dates[$day],
                        'avg_satang' => $average,
                        'low_satang' => (int) round($average * 0.97),
                        'high_satang' => (int) round($average * 1.04),
                        'sold_qty' => fake()->numberBetween(0, 3),
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ];

                    $offset = max(-self::BAND, min(self::BAND, $offset + fake()->randomFloat(4, -self::STEP, self::STEP)));
                }

                if (count($rows) >= 1000) {
                    $this->flush($rows);
                }
            });

        $this->flush($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array &$rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('price_points')->insertOrIgnore($chunk);
        }

        $rows = [];
    }
}
