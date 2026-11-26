<?php

namespace App\Actions;

use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Exceptions\BuylistQuotaFull;
use App\Exceptions\BuylistQuoteChanged;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\User;
use App\Notifications\BuylistSubmitted;
use App\Support\BuylistQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns the customer's list into a submission: locks the price rows, checks that the quote is still the one they saw
 * and that the weekly quota has room, counts the quantity against the quota and writes the submission with a copy of
 * every line. One transaction; either all of it happens or none of it.
 */
class SubmitBuylist
{
    /**
     * @param  list<array{buylist_price_id: int, qty: int}>  $lines  What the customer wants to sell, quota already applied by the form.
     * @param  string  $shownSignature  `BuylistQuote::signature()` of the lines as the page showed them.
     *
     * @throws InvalidArgumentException when the list is empty, too long, repeats a row, has a quantity outside 1 to `buylist.max_qty_per_line`, or the user is staff.
     * @throws BuylistQuoteChanged when a row is no longer bought or the prices or quantities differ from what was shown.
     * @throws BuylistQuotaFull when a line asks for more than the weekly quota has left.
     */
    public function handle(User $user, BuylistPayout $payout, array $lines, string $shownSignature): BuylistSubmission
    {
        if ($user->is_staff) {
            throw new InvalidArgumentException('Staff do not sell to their own shop through the storefront.');
        }

        if ($lines === [] || count($lines) > (int) config('buylist.max_lines')) {
            throw new InvalidArgumentException('A buylist has between 1 and '.config('buylist.max_lines').' lines.');
        }

        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) $line['buylist_price_id'];
            $qty = (int) $line['qty'];

            if ($qty < 1 || $qty > (int) config('buylist.max_qty_per_line') || isset($quantities[$id])) {
                throw new InvalidArgumentException('A buylist line needs one row and a quantity of 1 to '.config('buylist.max_qty_per_line').'.');
            }

            $quantities[$id] = $qty;
        }

        ksort($quantities);

        $submission = DB::transaction(function () use ($user, $payout, $quantities, $shownSignature): BuylistSubmission {
            // The price rows first, by ascending id, and the filters (still bought) are read under the same lock.
            $prices = BuylistPrice::query()
                ->buyable()
                ->whereKey(array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->with(['printing.card', 'printing.set'])
                ->get()
                ->keyBy('id');

            if ($prices->count() !== count($quantities)) {
                throw new BuylistQuoteChanged;
            }

            $shown = $prices->map(fn (BuylistPrice $price): array => ['price' => $price, 'qty' => $quantities[$price->getKey()]]);

            if (! hash_equals(BuylistQuote::signature($shown), $shownSignature)) {
                throw new BuylistQuoteChanged;
            }

            foreach ($prices as $price) {
                $remaining = $price->remainingQuota();

                if ($remaining !== null && $quantities[$price->getKey()] > $remaining) {
                    throw new BuylistQuotaFull($price->printing->display_name, $remaining);
                }
            }

            foreach ($prices as $price) {
                $price->increment('accepted_this_week', $quantities[$price->getKey()]);
            }

            $total = $prices->sum(fn (BuylistPrice $price): int => $price->unitFor($payout) * $quantities[$price->getKey()]);

            $submission = BuylistSubmission::query()->create([
                'number' => 'new-'.Str::ulid(),
                'user_id' => $user->getKey(),
                'payout' => $payout,
                'status' => BuylistStatus::Submitted,
                'quoted_total_satang' => $total,
                'quote_expires_at' => now()->setTimezone(config('shop.timezone'))->addDays((int) config('buylist.quote_days'))->endOfDay()->setTimezone(config('app.timezone')),
            ]);

            $submission->update(['number' => BuylistSubmission::numberFor($submission->getKey())]);

            foreach ($prices as $price) {
                $qty = $quantities[$price->getKey()];
                $unit = $price->unitFor($payout);

                $submission->items()->create([
                    'printing_id' => $price->printing_id,
                    'buylist_price_id' => $price->getKey(),
                    'card_name' => $price->printing->display_name,
                    'display_code' => $price->printing->display_code,
                    'language' => $price->printing->language,
                    'finish' => $price->finish,
                    'claimed_condition' => $price->condition,
                    'qty' => $qty,
                    'quoted_unit_satang' => $unit,
                    'quoted_line_satang' => $unit * $qty,
                ]);
            }

            return $submission;
        });

        session()->forget('buylist.lines');

        $user->notify(new BuylistSubmitted($submission));

        return $submission;
    }
}
