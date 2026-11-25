<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when staff confirm a slip: the order is paid in full, or the money so far is acknowledged and the rest is owed.
 */
class PaymentConfirmed extends Notification implements ShouldQueueAfterCommit
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
        $mail = (new MailMessage)
            ->subject(__('notifications.payment_confirmed.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]));

        if ($this->order->isPaid()) {
            $mail->line(__('notifications.payment_confirmed.paid', ['number' => $this->order->number]));
        } else {
            $mail->line(__('notifications.payment_confirmed.partial', [
                'number' => $this->order->number,
                'remaining' => Money::fromSatang($this->order->remainingSatang())->format(),
            ]));
        }

        return $mail
            ->action(__('notifications.payment_confirmed.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }
}
