<?php

namespace App\Actions;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutBlocked;
use App\Livewire\Forms\CheckoutData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Support\CartLines;
use App\Support\OrderRecipient;
use App\Support\PaymentQr;
use App\Support\PurchasableTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns a cart whose holds are all live into an order: takes the stock out for real, snapshots every line, writes the
 * first payment and consumes the holds. One transaction; either all of it happens or none of it.
 */
class PlaceOrder
{
    /**
     * Prices are the ones the customer saw (the line's held unit price), never the variant's price now.
     *
     * @throws CheckoutBlocked when the cart is empty, or a line has no live hold of its full quantity, can no longer
     *                         be bought, or has less stock than the hold says.
     * @throws InvalidArgumentException when the payment method is not one the shop offers.
     */
    public function handle(Cart $cart, CheckoutData $data, ?User $user = null): Order
    {
        $method = PaymentMethod::tryFrom($data->method);
        $courier = Courier::from($data->courier);

        if ($method === null || ! in_array($method, PaymentMethod::offered(), true)) {
            throw new InvalidArgumentException('That payment method is not offered.');
        }

        // Which rows to lock is read before the transaction opens: the first statement inside it must be the
        // locking read (project rule), and it cannot name rows it has not yet found. Anything that changed between
        // this read and the lock is caught by the re-read of the lines below. Ids are grouped by type: a graded
        // item and a variant may share an id.
        $refs = PurchasableTypes::referencedBy(CartItem::query()->where('cart_id', $cart->getKey())->get());

        $order = DB::transaction(function () use ($cart, $data, $method, $courier, $refs, $user): Order {
            // Variants first, then graded items, each by ascending id: every path that locks several types uses this order.
            $locked = PurchasableTypes::lockMany($refs);

            $items = $cart->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new CheckoutBlocked('The cart is empty.');
            }

            $reservations = StockReservation::query()
                ->where('cart_id', $cart->getKey())
                ->get()
                ->keyBy(fn (StockReservation $reservation): string => $reservation->purchasable_type.':'.$reservation->purchasable_id);

            $purchasableIds = collect($refs)
                ->map(fn ($ids, string $type) => PurchasableTypes::classFor($type)::purchasableIdsAmong($ids))
                ->all();

            $heldReservationIds = [];

            foreach ($items as $item) {
                $purchasable = $locked[$item->purchasable_type][$item->purchasable_id] ?? null;
                $reservation = $reservations->get($item->purchasable_type.':'.$item->purchasable_id);

                // The same rule the cart and checkout pages show: held in full, buyable, and in stock. The hold fitted the
                // stock when it was made, but staff may have lowered the stock since.
                if (! CartLines::canOrder($item, $purchasable, $reservation, isset($purchasableIds[$item->purchasable_type]) && $purchasableIds[$item->purchasable_type]->has($item->purchasable_id))) {
                    throw new CheckoutBlocked("Line {$item->id} can no longer be ordered as it stands.");
                }

                $heldReservationIds[] = $reservation->getKey();
            }

            $subtotal = $items->sum(fn (CartItem $item): int => $item->unit_price_satang * $item->qty);
            $shipping = $courier->rateSatang();
            // Protective packing is for singles: with no line outside a hard case there is nothing to pack, so a forced
            // `protection` is not charged.
            $packable = $items->contains(fn (CartItem $item): bool => ! $locked[$item->purchasable_type][$item->purchasable_id]->isInsured());
            $protection = $data->protection && $packable ? (int) config('shipping.protection_satang') : 0;
            // Mandatory hard case and parcel insurance, per insured item (a graded card), whatever the customer ticked.
            $insured = $items->sum(fn (CartItem $item): int => $locked[$item->purchasable_type][$item->purchasable_id]->isInsured() ? $item->qty : 0);
            $insurance = $insured * (int) config('shipping.graded_insurance_satang');
            $total = $subtotal + $shipping + $protection + $insurance;
            $normalized = $data->normalized();
            $placedAt = now();

            // The number comes from the row id, so it is set right after the insert; the placeholder keeps the unique
            // column valid for that instant.
            $order = Order::query()->create([
                'number' => 'new-'.Str::ulid(),
                'user_id' => $user?->getKey(),
                'status' => OrderStatus::AwaitingPayment,
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
                'protection_satang' => $protection,
                'insurance_satang' => $insurance,
                'total_satang' => $total,
                'payment_due_at' => $placedAt->copy()->addMinutes((int) config('shop.payment_window_minutes')),
                'placed_at' => $placedAt,
            ]);
            $order->update(['number' => Order::numberFor($order->getKey())]);

            foreach ($items as $item) {
                $purchasable = $locked[$item->purchasable_type][$item->purchasable_id];

                $order->items()->create([
                    'purchasable_type' => $purchasable->getMorphClass(),
                    'purchasable_id' => $purchasable->getKey(),
                    ...$purchasable->snapshot(),
                    'unit_price_satang' => $item->unit_price_satang,
                    'qty' => $item->qty,
                    'line_total_satang' => $item->unit_price_satang * $item->qty,
                ]);

                $purchasable->onSold($item->qty);
            }

            $order->payments()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount_satang' => $total,
                ...PaymentQr::attributes($method, $total, $order->payment_due_at),
            ]);

            // The holds are consumed, not released: the stock they held is now out of `qty` itself.
            // By the ids that were checked, not by cart: a line added to the cart meanwhile must survive.
            StockReservation::query()->whereKey($heldReservationIds)->delete();
            CartItem::query()->whereKey($items->modelKeys())->delete();

            return $order;
        });

        OrderRecipient::notify($order, new OrderPlaced($order));

        return $order;
    }
}
