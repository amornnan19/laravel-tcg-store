<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an order is cancelled, by the shop or because the payment window ran out.
 */
class OrderCancelled extends Notification implements ShouldQueue
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
            ->subject(__('notifications.order_cancelled.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.order_cancelled.line', ['number' => $this->order->number]))
            ->action(__('notifications.order_cancelled.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }
}
