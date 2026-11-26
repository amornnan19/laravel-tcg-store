<?php

namespace App\Actions;

use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Enums\StoreCreditReason;
use App\Exceptions\BuylistAwaitingCustomer;
use App\Exceptions\BuylistRefused;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\BuylistAccepted;
use App\Notifications\BuylistPaid;
use App\Support\BuylistAssessment;
use App\Support\VariantAlerts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The loop that fills the shelves: the cards become stock and the customer is paid in one transaction. Staff accept a
 * `received` submission nothing was marked down on; a `revised` one is accepted only by its owner, answering the new
 * total, never by staff (`BuylistAwaitingCustomer`). Variants the cards need and the shop does not have yet are created
 * first, unpriced (so unbuyable), through the unique index; then, in lock order: variants by id, the submission, the
 * customer's credit. Credit pays at once (`paid`); cash waits for the transfer (`accepted`).
 */
class AcceptBuylistSubmission
{
    /**
     * @param  ?User  $staff  Who accepted it, recorded on the credit entry.
     * @param  ?User  $customer  The owner confirming a revised total; null when staff accept.
     *
     * @throws BuylistAwaitingCustomer when staff try to accept a submission that is `revised`.
     * @throws BuylistRefused when the status does not allow it, the customer is not the owner, or there is nothing to pay.
     */
    public function handle(BuylistSubmission $submission, ?User $staff = null, ?User $customer = null): BuylistAcceptResult
    {
        // Before anything is created: a refused accept leaves no variants behind.
        $this->assertAcceptable(BuylistSubmission::query()->findOrFail($submission->getKey()), $customer);

        $lines = $this->stockLines($submission->items()->get());
        $created = $this->createMissingVariants($lines->keys());

        $variantIds = [];

        $accepted = DB::transaction(function () use ($submission, $staff, $customer, $lines, &$variantIds): BuylistSubmission {
            // A current read, locking the target variants by id: the first statement of the transaction.
            $variants = Variant::query()
                ->where(function (Builder $targets) use ($lines): void {
                    // No target (every line worth nothing) must not match every variant.
                    $targets->whereRaw('1 = 0');

                    foreach ($lines->keys() as $key) {
                        [$printingId, $condition, $finish] = explode(':', (string) $key);

                        $targets->orWhere(fn (Builder $one) => $one->where('printing_id', (int) $printingId)->where('condition', $condition)->where('finish', $finish));
                    }
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Variant $variant): string => $variant->printing_id.':'.$variant->condition->value.':'.$variant->finish->value);

            $variantIds = $variants->pluck('id')->all();

            $locked = BuylistSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());
            $this->assertAcceptable($locked, $customer);

            $items = $locked->items()->get();
            $locked->setRelation('items', $items);

            // The lines may have been assessed again since the variants were chosen.
            $stock = $this->stockLines($items);

            if ($stock->keys()->diff($variants->keys())->isNotEmpty()) {
                throw new BuylistRefused('changed');
            }

            $final = BuylistAssessment::finalTotal($locked);

            if ($final <= 0) {
                throw new BuylistRefused('nothing_to_pay');
            }

            foreach ($items as $item) {
                if (($item->assessed_line_satang ?? $item->quoted_line_satang) === 0 && $item->assessed_note !== __('admin.buylist.returned_with_parcel')) {
                    // Worth nothing to the shop: it is not absorbed, it goes back with the parcel.
                    $item->update(['assessed_note' => __('admin.buylist.returned_with_parcel')]);
                }
            }

            foreach ($stock as $key => $qty) {
                $variant = $variants[$key];
                $variant->update(['qty' => $variant->qty + $qty]);
            }

            $changes = ['final_total_satang' => $final, 'accepted_at' => now()];

            if ($customer !== null) {
                $changes['responded_at'] = now();
            }

            if ($locked->payout === BuylistPayout::Credit) {
                app(RecordStoreCredit::class)->handle(User::query()->findOrFail($locked->user_id), $final, StoreCreditReason::Buylist, $locked, staff: $staff);

                $changes += ['status' => BuylistStatus::Paid, 'paid_at' => now()];
            } else {
                $changes += ['status' => BuylistStatus::Accepted];
            }

            $locked->update($changes);

            return $locked;
        });

        VariantAlerts::dispatchFor($variantIds);

        $accepted->user->notify($accepted->status === BuylistStatus::Paid ? new BuylistPaid($accepted) : new BuylistAccepted($accepted));

        return new BuylistAcceptResult($accepted, $created);
    }

    /**
     * Makes the variants the lines need that the shop does not have yet: unpriced, with no stock. One insert per key that
     * ignores the unique key (printing, condition, finish), so it is safe inside an outer transaction (no failed insert
     * to roll back) and two accepts racing create it once. Returns how many it created.
     *
     * @param  Collection<int, string>  $keys  `printing:condition:finish`
     */
    private function createMissingVariants(Collection $keys): int
    {
        $created = 0;

        foreach ($keys as $key) {
            [$printingId, $condition, $finish] = explode(':', $key);

            $variant = new Variant(['printing_id' => (int) $printingId, 'condition' => $condition, 'finish' => $finish]);

            $created += Variant::query()->insertOrIgnore([
                'printing_id' => (int) $printingId,
                'condition' => $condition,
                'finish' => $finish,
                'sku' => Variant::makeSku($variant),
                'price_satang' => null,
                'qty' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $created;
    }

    /**
     * @throws BuylistAwaitingCustomer
     * @throws BuylistRefused
     */
    private function assertAcceptable(BuylistSubmission $submission, ?User $customer): void
    {
        if ($customer !== null) {
            if ($submission->user_id !== $customer->getKey()) {
                throw new BuylistRefused('not_owner');
            }

            if ($submission->status !== BuylistStatus::Revised) {
                throw new BuylistRefused('not_revised');
            }

            return;
        }

        if ($submission->status === BuylistStatus::Revised) {
            throw new BuylistAwaitingCustomer;
        }

        if ($submission->status !== BuylistStatus::Received) {
            throw new BuylistRefused('illegal_status');
        }
    }

    /**
     * The quantity each target variant (`printing:condition:finish`) receives. A line that is worth nothing is left out.
     *
     * @param  Collection<int, BuylistSubmissionItem>  $items
     * @return Collection<string, int>
     */
    private function stockLines(Collection $items): Collection
    {
        return $items
            ->filter(fn (BuylistSubmissionItem $item): bool => ($item->assessed_line_satang ?? $item->quoted_line_satang) > 0)
            ->groupBy(fn (BuylistSubmissionItem $item): string => $item->printing_id.':'.($item->assessed_condition ?? $item->claimed_condition)->value.':'.$item->finish->value)
            ->map(fn (Collection $group): int => (int) $group->sum('qty'))
            ->sortKeys();
    }
}
