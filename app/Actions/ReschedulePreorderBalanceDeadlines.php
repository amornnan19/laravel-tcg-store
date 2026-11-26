<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PreorderPlan;
use App\Models\Order;
use App\Models\Preorder;
use App\Notifications\PreorderBalanceDue;
use App\Support\OrderRecipient;
use Illuminate\Support\Facades\DB;

/**
 * After staff move a pre-order's release date, gives every balance that was already requested its new deadline
 * (`Preorder::balanceDueAt()`, never below its floor) and tells the customer. Each order is handled under its own row
 * lock and then the pre-order's, the same order `CancelOrder` takes them in. Every live deposit order is listed, asked for
 * or not: whether its balance was requested is decided under its lock, so a request that was in flight when the list was
 * read has committed by then and is corrected, and `RequestPreorderBalance` never has to lock the pre-order itself (it
 * inserts a payment, and a pre-order lock there would bring back the payments gap-lock deadlock). Idempotent: an order at
 * its target is skipped, so a retry sends no second message. Call it after the panel's save transaction has committed.
 */
class ReschedulePreorderBalanceDeadlines
{
    /**
     * @return int How many orders got a new deadline.
     */
    public function handle(Preorder $preorder): int
    {
        $moved = 0;

        Order::query()
            ->where('preorder_id', $preorder->getKey())
            ->where('preorder_plan', PreorderPlan::Deposit->value)
            ->whereIn('status', [OrderStatus::AwaitingPayment->value, OrderStatus::AwaitingBalance->value])
            ->lazyById()
            ->each(function (Order $listed) use ($preorder, &$moved): void {
                $order = DB::transaction(function () use ($listed, $preorder): ?Order {
                    $order = Order::query()->lockForUpdate()->findOrFail($listed->getKey());
                    $current = Preorder::lockForPlacing($preorder->getKey());

                    if ($current === null || $order->status !== OrderStatus::AwaitingBalance || $order->balance_requested_at === null) {
                        return null;
                    }

                    $due = $current->balanceDueAt();

                    if ($order->payment_due_at->format('Y-m-d H:i:s') === $due->format('Y-m-d H:i:s')) {
                        return null;
                    }

                    $order->update(['payment_due_at' => $due]);

                    return $order;
                });

                if ($order !== null) {
                    $moved++;
                    OrderRecipient::notify($order, new PreorderBalanceDue($order, moved: true));
                }
            });

        return $moved;
    }
}
