<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when staff ship an order: the courier, the tracking number and the courier's own tracking link.
 */
class OrderShipped extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

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
}
