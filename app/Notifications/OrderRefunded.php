<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when staff refund an order, with the amount being returned.
 */
class OrderRefunded extends ShopNotification
{
    public function __construct(public Order $order, public int $refundedSatang) {}

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

    public function toLine(object $notifiable): string
    {
        return __('notifications.order_refunded.line_text', [
            'number' => $this->order->number,
            'amount' => Money::fromSatang($this->refundedSatang)->format(),
        ])
            ."\n".$this->order->signedUrl();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->order->contact_email;
    }
}
