<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent seven days before a release: the balance of a deposit pre-order is due, with the signed link to the same order,
 * whose payment panel takes it. What it says is fixed when it is made: the order and its product may change before the
 * queue runs.
 */
class PreorderBalanceDue extends ShopNotification
{
    public string $amount;

    public string $dueOn;

    public string $contactEmail;

    public function __construct(public Order $order, public bool $moved = false)
    {
        $this->amount = Money::fromSatang($order->balance_due_satang)->format();
        $this->dueOn = $order->paymentDueLabel();
        $this->contactEmail = $order->contact_email;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__($this->moved ? 'notifications.preorder_balance_due.moved_subject' : 'notifications.preorder_balance_due.subject', ['number' => $this->order->number]))
            ->greeting(__('notifications.greeting', ['name' => $this->order->contact_name]))
            ->line(__($this->moved ? 'notifications.preorder_balance_due.moved_line' : 'notifications.preorder_balance_due.line', ['number' => $this->order->number, 'amount' => $this->amount, 'date' => $this->dueOn]))
            ->line(__('notifications.preorder_balance_due.deadline', ['date' => $this->dueOn]))
            ->action(__('notifications.preorder_balance_due.action'), $this->order->signedUrl())
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return __($this->moved ? 'notifications.preorder_balance_due.moved_line_text' : 'notifications.preorder_balance_due.line_text', [
            'number' => $this->order->number,
            'amount' => $this->amount,
            'date' => $this->dueOn,
        ])
            ."\n".$this->order->signedUrl();
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->contactEmail;
    }
}
