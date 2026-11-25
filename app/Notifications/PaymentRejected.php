<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when staff reject a slip: the reason verbatim, line by line, and the link back to the payment panel.
 */
class PaymentRejected extends ShopNotification
{
    public function __construct(public Order $order, public Payment $payment) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.payment_rejected.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__('notifications.payment_rejected.line', ['number' => $this->order->number]));

        foreach (preg_split('/\R/u', (string) $this->payment->rejected_reason) ?: [] as $reasonLine) {
            $mail->line($reasonLine);
        }

        return $mail
            ->line(__('notifications.payment_rejected.deadline', ['minutes' => config('shop.payment_window_minutes')]))
            ->action(__('notifications.payment_rejected.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return __('notifications.payment_rejected.line_text', [
            'number' => $this->order->number,
            'reason' => trim((string) $this->payment->rejected_reason),
        ]).' '.__('notifications.payment_rejected.line_deadline', ['minutes' => config('shop.payment_window_minutes')])
            ."\n".$this->order->signedUrl();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->order->contact_email;
    }
}
