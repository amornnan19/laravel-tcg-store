<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the order's own email when it is placed, with the signed link that opens the order page.
 */
class OrderPlaced extends ShopNotification
{
    public function __construct(public Order $order) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.order_placed.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.order_placed.line', ['number' => $this->order->number, 'total' => Money::fromSatang($this->order->total_satang)->format()]))
            ->line(__('notifications.order_placed.deadline', ['minutes' => config('shop.payment_window_minutes')]))
            ->action(__('notifications.order_placed.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return __('notifications.order_placed.line_text', [
            'number' => $this->order->number,
            'total' => Money::fromSatang($this->order->total_satang)->format(),
            'minutes' => config('shop.payment_window_minutes'),
        ])
            ."\n".$this->order->signedUrl();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->order->contact_email;
    }
}
