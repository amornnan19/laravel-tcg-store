<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when staff mark an order delivered.
 */
class OrderDelivered extends Notification implements ShouldQueueAfterCommit
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
        return (new MailMessage)
            ->subject(__('notifications.order_delivered.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.order_delivered.line', ['number' => $this->order->number]))
            ->action(__('notifications.order_delivered.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }
}
