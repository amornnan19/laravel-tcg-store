<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when staff ship an order: the courier, the tracking number and the courier's own tracking link.
 */
class OrderShipped extends ShopNotification
{
    public function __construct(public Order $order) {}

    public function toMail(object $notifiable): MailMessage
    {
        $tracking = (string) $this->order->tracking_number;

        return (new MailMessage)
            ->subject(__('notifications.order_shipped.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.order_shipped.line', [
                'number' => $this->order->number,
                'courier' => $this->order->courier->label(),
                'tracking' => $tracking,
            ]))
            ->line(__('notifications.order_shipped.track', ['url' => $this->order->courier->trackingUrl($tracking)]))
            ->action(__('notifications.order_shipped.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        $tracking = (string) $this->order->tracking_number;

        return __('notifications.order_shipped.line_text', [
            'number' => $this->order->number,
            'courier' => $this->order->courier->label(),
            'tracking' => $tracking,
        ])
            ."\n".$this->order->courier->trackingUrl($tracking)
            ."\n".$this->order->signedUrl();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->order->contact_email;
    }
}
