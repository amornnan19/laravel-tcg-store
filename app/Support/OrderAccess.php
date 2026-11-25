<?php

namespace App\Support;

use App\Models\Order;

/**
 * Whether the visitor's own session placed or opened the order, or the signed-in customer owns it. The order page also opens on a valid signature; the
 * payment panel's requests and the QR download carry no signature of their own and rely on this alone.
 */
final class OrderAccess
{
    public static function ownedBySession(Order $order): bool
    {
        return in_array($order->getKey(), (array) session()->get('orders', []), true);
    }

    /**
     * The session placed or opened the order, or the signed-in customer owns it.
     */
    public static function canView(Order $order): bool
    {
        return self::ownedBySession($order)
            || (auth()->id() !== null && $order->user_id === auth()->id());
    }
}
