<?php

namespace App\Actions;

use App\Enums\Courier;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use App\Exceptions\PreorderRefused;
use App\Livewire\Forms\CheckoutData;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\SealedProduct;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Support\OrderRecipient;
use App\Support\PaymentQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Places a pre-order: a place in a release, paid by deposit now and the balance before the release, or in full now at a
 * discount. It is its own order and takes no stock. The pre-order row is locked first, so the quota and the account limit
 * are decided against what every concurrent attempt has already committed, and `reserved_count` moves in the same
 * transaction as the order.
 */
class PlacePreorder
{
    /**
     * @throws PreorderRefused when the pre-order is not open (any more), has fewer places left than asked for, or the
     *                         account would pass its limit.
     * @throws InvalidArgumentException when the quantity is below one or the payment method is not one the shop offers.
     */
    public function handle(Preorder $preorder, User $user, CheckoutData $data, int $qty, PreorderPlan $plan): Order
    {
        $method = PaymentMethod::tryFrom($data->method);
        $courier = Courier::from($data->courier);

        if ($qty < 1) {
            throw new InvalidArgumentException('A pre-order is for at least one.');
        }

        if ($method === null || ! in_array($method, PaymentMethod::offered(), true)) {
            throw new InvalidArgumentException('That payment method is not offered.');
        }

        $order = DB::transaction(function () use ($preorder, $user, $data, $qty, $plan, $method, $courier): Order {
            // First statement of the transaction: the lock every decision below depends on.
            $locked = Preorder::lockForPlacing($preorder->getKey()) ?? throw new PreorderRefused('closed');

            $state = $locked->state();

            if ($state !== 'open') {
                throw new PreorderRefused($state);
            }

            $product = SealedProduct::query()->searchVisible()->where('is_preorder', true)->find($locked->sealed_product_id)
                ?? throw new PreorderRefused('unavailable');

            $allowed = $locked->maxQtyFor($user);

            if ($qty > $allowed) {
                // `limit` when the account is what stops it, `full` when the places are.
                throw new PreorderRefused($locked->per_account_limit - $locked->reservedBy($user) <= $locked->placesLeft() ? 'limit' : 'full', $allowed);
            }

            $locked->increment('reserved_count', $qty);

            $unit = $plan === PreorderPlan::Full ? $locked->fullPlanUnitSatang() : $locked->full_satang;
            $subtotal = $unit * $qty;
            $shipping = $courier->rateSatang();
            $total = $subtotal + $shipping;
            $deposit = min($locked->deposit_satang * $qty, $subtotal);
            // The deposit plan asks for the deposit now; the shipping and the rest wait for the balance request.
            $firstAmount = $plan === PreorderPlan::Deposit ? $deposit : $total;
            $normalized = $data->normalized();
            $placedAt = now();

            $order = Order::query()->create([
                'number' => 'new-'.Str::ulid(),
                'user_id' => $user->getKey(),
                'status' => OrderStatus::AwaitingPayment,
                'kind' => OrderKind::Preorder,
                'preorder_id' => $locked->getKey(),
                'preorder_plan' => $plan,
                'cancel_until' => $locked->cancel_until,
                'deposit_satang' => $deposit,
                'balance_due_satang' => $plan === PreorderPlan::Deposit ? $total - $deposit : 0,
                'contact_name' => $normalized['name'],
                'contact_phone' => $normalized['phone'],
                'contact_email' => $normalized['email'],
                'address' => [
                    'recipient' => $normalized['name'],
                    'phone' => $normalized['phone'],
                    'line1' => $normalized['line1'],
                    'subdistrict' => $normalized['subdistrict'],
                    'district' => $normalized['district'],
                    'province' => $normalized['province'],
                    'postcode' => $normalized['postcode'],
                ],
                'courier' => $courier,
                'subtotal_satang' => $subtotal,
                'shipping_satang' => $shipping,
                'protection_satang' => 0,
                'insurance_satang' => 0,
                'total_satang' => $total,
                'payment_due_at' => $placedAt->copy()->addMinutes((int) config('shop.payment_window_minutes')),
                'placed_at' => $placedAt,
            ]);
            $order->update(['number' => Order::numberFor($order->getKey())]);

            $order->items()->create([
                'purchasable_type' => $product->getMorphClass(),
                'purchasable_id' => $product->getKey(),
                ...$product->snapshot(),
                'unit_price_satang' => $unit,
                'qty' => $qty,
                'line_total_satang' => $subtotal,
            ]);

            $order->payments()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount_satang' => $firstAmount,
                ...PaymentQr::attributes($method, $firstAmount, $order->payment_due_at),
            ]);

            return $order;
        });

        OrderRecipient::notify($order, new OrderPlaced($order));

        return $order;
    }
}
