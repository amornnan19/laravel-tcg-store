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
use App\Models\Variant;
use App\Notifications\OrderPlaced;
use App\Support\CartLines;
use App\Support\PaymentQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

        // Which variants to lock is read before the transaction opens: the first statement inside it must be the
        // locking read (project rule), and it cannot name rows it has not yet found. Anything that changed between
        // this read and the lock is caught by the re-read of the lines below.
        $variantIds = CartItem::query()
            ->where('cart_id', $cart->getKey())
            ->orderBy('purchasable_id')
            ->pluck('purchasable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $order = DB::transaction(function () use ($cart, $data, $method, $courier, $variantIds, $user): Order {
            $variants = Variant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $items = $cart->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new CheckoutBlocked('The cart is empty.');
            }

            $reservations = StockReservation::query()
                ->where('cart_id', $cart->getKey())
                ->get()
                ->keyBy(fn (StockReservation $reservation): string => $reservation->purchasable_type.':'.$reservation->purchasable_id);

            $purchasableIds = Variant::query()->purchasable()->whereIn('id', $variantIds)->pluck('id')->flip();

            $heldReservationIds = [];

            foreach ($items as $item) {
                $variant = $variants->get($item->purchasable_id);
                $reservation = $reservations->get($item->purchasable_type.':'.$item->purchasable_id);

                // The same rule the cart and checkout pages show: held in full, buyable, and in stock. The hold fitted the
                // stock when it was made, but staff may have lowered the stock since.
                if (! CartLines::canOrder($item, $variant, $reservation, $purchasableIds->has($item->purchasable_id))) {
                    throw new CheckoutBlocked("Line {$item->id} can no longer be ordered as it stands.");
                }

                $heldReservationIds[] = $reservation->getKey();
            }

            $variants->load(['printing.card', 'printing.set']);

            $subtotal = $items->sum(fn (CartItem $item): int => $item->unit_price_satang * $item->qty);
            $shipping = $courier->rateSatang();
            $protection = $data->protection ? (int) config('shipping.protection_satang') : 0;
            $total = $subtotal + $shipping + $protection;
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
                'total_satang' => $total,
                'payment_due_at' => $placedAt->copy()->addMinutes((int) config('shop.payment_window_minutes')),
                'placed_at' => $placedAt,
            ]);
            $order->update(['number' => Order::numberFor($order->getKey())]);

            foreach ($items as $item) {
                $variant = $variants->get($item->purchasable_id);
                $printing = $variant->printing;

                $order->items()->create([
                    'purchasable_type' => $variant->getMorphClass(),
                    'purchasable_id' => $variant->getKey(),
                    'card_name' => $printing->display_name,
                    'set_code' => $printing->set->code,
                    'number' => $printing->number,
                    'language' => $printing->language->value,
                    'condition' => $variant->condition->value,
                    'finish' => $variant->finish->value,
                    'unit_price_satang' => $item->unit_price_satang,
                    'qty' => $item->qty,
                    'line_total_satang' => $item->unit_price_satang * $item->qty,
                ]);

                $variant->qty -= $item->qty;
                $variant->save();
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

        Notification::route('mail', $order->contact_email)->notify(new OrderPlaced($order));

        return $order;
    }
}
