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
        $mail = (new MailMessage)
            ->subject(__('notifications.order_placed.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]));

        // A pre-order took no stock and, on the deposit plan, asks for the deposit now and the rest later.
        if ($this->order->isPreorder()) {
            $mail->line(__('notifications.order_placed.preorder_line', ['number' => $this->order->number, 'due' => $this->dueNow()]))
                ->line(__('notifications.order_placed.preorder_deadline', ['minutes' => config('shop.payment_window_minutes')]));
        } elseif ($this->order->isCoveredByCredit()) {
            $mail->line(__('notifications.order_placed.credit_paid_line', ['number' => $this->order->number, 'credit' => Money::fromSatang($this->order->credit_used_satang)->format()]));
        } else {
            $mail->line(__('notifications.order_placed.line', ['number' => $this->order->number, 'total' => Money::fromSatang($this->order->payableSatang())->format()]));

            if ($this->order->credit_used_satang > 0) {
                $mail->line(__('notifications.order_placed.credit_used', ['credit' => Money::fromSatang($this->order->credit_used_satang)->format()]));
            }

            $mail->line(__('notifications.order_placed.deadline', ['minutes' => config('shop.payment_window_minutes')]));
        }

        return $mail
            ->action(__($this->order->isCoveredByCredit() ? 'notifications.order_placed.action_paid' : 'notifications.order_placed.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        if ($this->order->isCoveredByCredit()) {
            return __('notifications.order_placed.credit_paid_line_text', ['number' => $this->order->number])
                ."\n".$this->order->signedUrl();
        }

        return __($this->order->isPreorder() ? 'notifications.order_placed.preorder_line_text' : 'notifications.order_placed.line_text', [
            'number' => $this->order->number,
            'total' => $this->order->isPreorder() ? $this->dueNow() : Money::fromSatang($this->order->payableSatang())->format(),
            'minutes' => config('shop.payment_window_minutes'),
        ])
            ."\n".$this->order->signedUrl();
    }

    /**
     * What the first payment asks for: the amount of the order's payment, which is the deposit on the deposit plan.
     */
    private function dueNow(): string
    {
        return Money::fromSatang($this->order->amountDueNowSatang())->format();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->order->contact_email;
    }
}
