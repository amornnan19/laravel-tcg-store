<?php

namespace App\Notifications;

use App\Models\BuylistSubmission;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when a cash submission is confirmed and the transfer is queued.
 */
class BuylistAccepted extends ShopNotification
{
    public string $number;

    /** The customer's address, kept so a LINE push that ultimately fails can still go out by email. */
    public ?string $email;

    public string $amount;

    public function __construct(BuylistSubmission $submission)
    {
        $this->number = $submission->number;
        $this->email = $submission->user?->email;
        $this->amount = Money::fromSatang($submission->totalSatang())->format();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.buylist_accepted.subject', ['number' => $this->number]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->text())
            ->action(__('notifications.buylist_accepted.action'), route('buylist.show', $this->number))
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return $this->text()."\n".route('buylist.show', $this->number);
    }

    private function text(): string
    {
        return __('notifications.buylist_accepted.line_text', ['number' => $this->number, 'amount' => $this->amount]);
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->email;
    }
}
