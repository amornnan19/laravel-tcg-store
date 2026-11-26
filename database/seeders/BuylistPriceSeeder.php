<?php

namespace Database\Seeders;

use App\Actions\SubmitBuylist;
use App\Enums\BuylistPayout;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Models\BuylistPrice;
use App\Models\User;
use App\Support\BuylistQuote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BuylistPriceSeeder extends Seeder
{
    private const PRINTINGS = 30;

    /** What the shop pays for a card in each condition, as a share of the price it sells the NM copy for. */
    private const SHARE_PERCENT = [
        CardCondition::Nm->value => 55,
        CardCondition::Lp->value => 47,
        CardCondition::Mp->value => 40,
    ];

    /**
     * Buy prices for about thirty seeded printings, from their NM selling price, with the configured credit bonus and a
     * weekly quota on a few. Local only: one submitted list for the demo customer, so the account page has something to
     * show. Skipped for a printing that already has its rows, so it can run again.
     */
    public function run(): void
    {
        $nmVariants = DB::table('variants')
            ->join('printings', 'printings.id', '=', 'variants.printing_id')
            ->where('variants.condition', CardCondition::Nm->value)
            ->whereNotNull('variants.price_satang')
            ->where('variants.price_satang', '>=', 10000)
            ->whereNull('printings.deleted_at')
            ->orderBy('variants.printing_id')
            ->orderBy('variants.finish')
            ->select(['variants.printing_id', 'variants.finish', 'variants.price_satang', 'printings.has_foil'])
            ->limit(self::PRINTINGS * 2)
            ->get();

        $seeded = 0;

        foreach ($nmVariants->groupBy('printing_id') as $printingId => $variants) {
            if ($seeded >= self::PRINTINGS) {
                break;
            }

            $normal = $variants->firstWhere('finish', Finish::Normal->value);

            if ($normal === null || BuylistPrice::query()->where('printing_id', $printingId)->exists()) {
                continue;
            }

            // A few printings carry a weekly quota; one of those is already half used.
            $quota = $seeded % 7 === 0 ? 10 : null;

            foreach (self::SHARE_PERCENT as $condition => $share) {
                $this->price($printingId, $condition, Finish::Normal, $normal->price_satang, $share, $quota, $seeded % 14 === 0 ? 5 : 0);
            }

            $foil = $variants->firstWhere('finish', Finish::Foil->value);

            if ($foil !== null && $foil->has_foil) {
                $this->price($printingId, CardCondition::Nm->value, Finish::Foil, $foil->price_satang, self::SHARE_PERCENT[CardCondition::Nm->value], null, 0);
            }

            $seeded++;
        }

        $this->seedDemoSubmission();
    }

    private function price(int $printingId, string $condition, Finish $finish, int $sellingSatang, int $sharePercent, ?int $quota, int $taken): void
    {
        // Whole baht, and never below ฿1.
        $cash = max(100, intdiv($sellingSatang * $sharePercent + 5000, 10000) * 100);

        BuylistPrice::query()->firstOrCreate(
            ['printing_id' => $printingId, 'condition' => $condition, 'finish' => $finish->value],
            ['cash_satang' => $cash, 'credit_satang' => BuylistPrice::creditFor($cash), 'weekly_quota' => $quota, 'is_active' => true],
        );

        if ($taken > 0) {
            BuylistPrice::query()
                ->where(['printing_id' => $printingId, 'condition' => $condition, 'finish' => $finish->value])
                ->update(['accepted_this_week' => $taken]);
        }
    }

    /**
     * One list waiting for its cards, sent by the demo customer, through the same action the shop uses.
     */
    private function seedDemoSubmission(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $customer = User::query()->where('email', 'customer@example.com')->first()
            ?? User::factory()->create(['name' => 'Demo Customer', 'email' => 'customer@example.com']);

        if ($customer->buylistSubmissions()->exists()) {
            return;
        }

        $prices = BuylistPrice::query()->buyable()
            ->where('condition', CardCondition::Nm->value)
            ->where('finish', Finish::Normal->value)
            ->whereNull('weekly_quota')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($prices->isEmpty()) {
            return;
        }

        $quantities = $prices->values()->map(fn (BuylistPrice $price, int $index): array => ['price' => $price, 'qty' => $index + 1]);

        app(SubmitBuylist::class)->handle(
            $customer,
            BuylistPayout::Credit,
            $quantities->map(fn (array $line): array => ['buylist_price_id' => $line['price']->getKey(), 'qty' => $line['qty']])->all(),
            BuylistQuote::signature($quantities),
        );
    }
}
