<?php

namespace App\Notifications;

use App\Models\BuylistSubmission;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when the shop found cards in a worse condition than the customer claimed: how many, and the new total they are
 * asked to confirm. What it says is fixed when it is made.
 */
class BuylistRevised extends ShopNotification
{
    public string $number;

    /** The customer's address, kept so a LINE push that ultimately fails can still go out by email. */
    public ?string $email;

    public string $final;

    public int $count;

    public function __construct(BuylistSubmission $submission)
    {
        $this->number = $submission->number;
        $this->email = $submission->user?->email;
        $this->final = Money::fromSatang($submission->final_total_satang)->format();
        $this->count = (int) $submission->items
            ->filter(fn ($item): bool => $item->assessed_condition !== null && $item->assessed_condition !== $item->claimed_condition)
            ->sum('qty');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.buylist_revised.subject', ['number' => $this->number]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->text())
            ->action(__('notifications.buylist_revised.action'), route('buylist.show', $this->number))
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return $this->text()."\n".route('buylist.show', $this->number);
    }

    private function text(): string
    {
        return __('notifications.buylist_revised.line_text', ['number' => $this->number, 'n' => $this->count, 'final' => $this->final]);
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return $this->email;
    }
}
