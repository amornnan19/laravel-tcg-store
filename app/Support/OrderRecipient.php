<?php

namespace App\Support;

use App\Models\Order;
use App\Notifications\ShopNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Who an order's messages go to: the owner's LINE when they linked it and opted in, otherwise the email the order was
 * placed with. A guest order always goes to its contact email.
 */
final class OrderRecipient
{
    public static function notify(Order $order, ShopNotification $notification): void
    {
        $user = $order->user;

        if ($user !== null && $user->prefersLine()) {
            $user->notify($notification);

            return;
        }

        Notification::route('mail', $order->contact_email)->notify($notification);
    }
}
