<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when staff refund an order, with the amount being returned.
 */
class OrderRefunded extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public Order $order, public int $refundedSatang) {}

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
            ->subject(__('notifications.order_refunded.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.order_refunded.line', [
                'number' => $this->order->number,
                'amount' => Money::fromSatang($this->refundedSatang)->format(),
            ]))
            ->action(__('notifications.order_refunded.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }
}
