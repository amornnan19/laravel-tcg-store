<?php

namespace App\Notifications;

use App\Models\BuylistSubmission;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when a customer's list is received: the number, the quoted total and the day the quote runs out. What it says
 * is fixed when it is made, so a later change to the submission never rewrites the message.
 */
class BuylistSubmitted extends ShopNotification
{
    public string $number;

    /** The customer's address, kept so a LINE push that ultimately fails can still go out by email. */
    public ?string $email;

    public string $total;

    public string $expires;

    public function __construct(BuylistSubmission $submission)
    {
        $this->number = $submission->number;
        $this->email = $submission->user?->email;
        $this->total = Money::fromSatang($submission->quoted_total_satang)->format();
        $this->expires = $submission->quote_expires_at->copy()->setTimezone(config('shop.timezone'))->locale('th')->translatedFormat('j M Y');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.buylist_submitted.subject', ['number' => $this->number]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.buylist_submitted.line_text', ['number' => $this->number, 'total' => $this->total, 'date' => $this->expires]))
            ->action(__('notifications.buylist_submitted.action'), route('buylist.show', $this->number))
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return __('notifications.buylist_submitted.line_text', ['number' => $this->number, 'total' => $this->total, 'date' => $this->expires])
            ."\n".route('buylist.show', $this->number);
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->email;
    }
}
