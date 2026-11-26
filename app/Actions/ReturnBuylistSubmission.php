<?php

namespace App\Actions;

use App\Enums\BuylistStatus;
use App\Exceptions\BuylistRefused;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Sends the cards back, free: staff from `submitted`, `received` or `revised`, or the customer from `revised` when they
 * would rather not take the new total. Either way the quantity goes back to the weekly quota, once
 * (`quota_released_at`), unless the list is from before this week's reset. Staff also use it to record the tracking number of a parcel that is already `returned`, or of the cards an accepted list sends back.
 * Lock order: the buy price rows by id, then the submission.
 */
class ReturnBuylistSubmission
{
    /**
     * @param  ?User  $customer  The owner answering a revised total; null for staff.
     *
     * @throws BuylistRefused when the status does not allow it, the customer is not the owner, or a tracking number is asked for and missing or already there.
     */
    public function handle(BuylistSubmission $submission, ?string $tracking = null, ?User $customer = null): BuylistSubmission
    {
        $tracking = filled($tracking) ? trim($tracking) : null;
        $priceIds = $submission->items()->whereNotNull('buylist_price_id')->pluck('buylist_price_id')->unique()->sort()->values()->all();

        return DB::transaction(function () use ($submission, $tracking, $customer, $priceIds): BuylistSubmission {
            $trackingOnly = false;

            $prices = BuylistPrice::query()->whereKey($priceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $locked = BuylistSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());

            if ($customer !== null) {
                if ($locked->user_id !== $customer->getKey()) {
                    throw new BuylistRefused('not_owner');
                }

                if ($locked->status !== BuylistStatus::Revised) {
                    throw new BuylistRefused('not_revised');
                }

                // The cards are shipped back later: `returned_at` is set when staff record the parcel.
                $changes = ['status' => BuylistStatus::Returned, 'responded_at' => now()];
            } elseif (in_array($locked->status, [BuylistStatus::Submitted, BuylistStatus::Received, BuylistStatus::Revised], true)) {
                $changes = ['status' => BuylistStatus::Returned, 'returned_at' => now()];
            } elseif ($locked->status === BuylistStatus::Returned && $locked->return_tracking === null) {
                $changes = ['returned_at' => $locked->returned_at ?? now()];
            } elseif (in_array($locked->status, [BuylistStatus::Accepted, BuylistStatus::Paid], true) && $locked->return_tracking === null && $locked->items()->where('assessed_line_satang', 0)->exists()) {
                // Cards the shop did not take go back with the parcel; only their tracking is recorded, the status stays.
                $trackingOnly = true;
                $changes = [];
            } else {
                throw new BuylistRefused('illegal_status');
            }

            if ($tracking !== null) {
                $changes['return_tracking'] = $tracking;
            } elseif ($locked->status === BuylistStatus::Returned || $trackingOnly) {
                throw new BuylistRefused('tracking_required');
            }

            if (! $trackingOnly && $locked->quota_released_at === null) {
                // The weekly reset already cleared what a list from an earlier week had counted: giving that back would take
                // from this week's quota. Such a list is only stamped.
                if ($locked->created_at->gte(now()->setTimezone(config('shop.timezone'))->startOfWeek(CarbonInterface::MONDAY))) {
                    foreach ($locked->items()->get() as $item) {
                        $price = $prices[$item->buylist_price_id] ?? null;

                        $price?->forceFill(['accepted_this_week' => max(0, $price->accepted_this_week - $item->qty)])->save();
                    }
                }

                $changes['quota_released_at'] = now();
            }

            $locked->update($changes);

            return $locked;
        });
    }
}
