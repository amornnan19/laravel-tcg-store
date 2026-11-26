<?php

namespace App\Notifications;

use App\Models\BuylistSubmission;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when the customer has been paid: credit that is already in their account, or the cash transfer with its
 * reference. What it says is fixed when it is made.
 */
class BuylistPaid extends ShopNotification
{
    public string $number;

    /** The customer's address, kept so a LINE push that ultimately fails can still go out by email. */
    public ?string $email;

    public string $amount;

    public ?string $reference;

    public function __construct(BuylistSubmission $submission)
    {
        $this->number = $submission->number;
        $this->email = $submission->user?->email;
        $this->amount = Money::fromSatang($submission->totalSatang())->format();
        $this->reference = $submission->payout_reference;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.buylist_paid.subject', ['number' => $this->number]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->text())
            ->action(__('notifications.buylist_paid.action'), route('buylist.show', $this->number))
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return $this->text()."\n".route('buylist.show', $this->number);
    }

    private function text(): string
    {
        return $this->reference === null
            ? __('notifications.buylist_paid.line_credit', ['number' => $this->number, 'amount' => $this->amount])
            : __('notifications.buylist_paid.line_cash', ['number' => $this->number, 'amount' => $this->amount, 'ref' => $this->reference]);
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->email;
    }
}
