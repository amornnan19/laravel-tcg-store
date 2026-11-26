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
    public function __construct(public Order $order, public int $refundedSatang, public int $creditReturnedSatang = 0) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.order_refunded.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]));

        // An order paid wholly with store credit has no money to return: it says only that the credit is back.
        if ($this->refundedSatang > 0) {
            $mail->line(__('notifications.order_refunded.line', [
                'number' => $this->order->number,
                'amount' => Money::fromSatang($this->refundedSatang)->format(),
            ]));
        } else {
            $mail->line(__('notifications.order_refunded.line_no_money', ['number' => $this->order->number]));
        }

        if ($this->creditReturnedSatang > 0) {
            $mail->line(__('notifications.credit_returned', ['amount' => Money::fromSatang($this->creditReturnedSatang)->format()]));
        }

        return $mail
            ->action(__('notifications.order_refunded.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        $text = $this->refundedSatang > 0
            ? __('notifications.order_refunded.line_text', [
                'number' => $this->order->number,
                'amount' => Money::fromSatang($this->refundedSatang)->format(),
            ])
            : __('notifications.order_refunded.line_text_no_money', ['number' => $this->order->number]);

        if ($this->creditReturnedSatang > 0) {
            $text .= ' '.__('notifications.credit_returned', ['amount' => Money::fromSatang($this->creditReturnedSatang)->format()]);
        }

        return $text
            ."\n".$this->order->signedUrl();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->order->contact_email;
    }
}
