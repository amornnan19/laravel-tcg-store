<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an order is cancelled, by the shop or because the payment window ran out.
 */
class OrderCancelled extends Notification implements ShouldQueueAfterCommit
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
        $received = $this->order->receivedSatang();

        $mail = (new MailMessage)
            ->subject(__('notifications.order_cancelled.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.order_cancelled.line', ['number' => $this->order->number]));

        // Money already in comes back: say so, with the amount, so the customer is not left wondering.
        if ($received > 0) {
            $mail->line(__('notifications.order_cancelled.refund_line', ['amount' => Money::fromSatang($received)->format()]));
        }

        return $mail
            ->action(__('notifications.order_cancelled.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }
}
