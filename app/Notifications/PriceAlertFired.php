<?php

namespace App\Notifications;

use App\Models\Variant;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent once when a variant's price reaches the one the customer set.
 */
class PriceAlertFired extends VariantAlertFired
{
    public function __construct(Variant $variant, public int $targetSatang, ?string $email = null)
    {
        parent::__construct($variant, $email);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.price_alert.subject', ['card' => $this->cardName]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->text())
            ->action(__('notifications.price_alert.action'), $this->cardUrl)
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return $this->text()."\n".$this->cardUrl;
    }

    private function text(): string
    {
        return __('notifications.price_alert.line_text', [
            'card' => $this->cardName,
            'meta' => $this->meta,
            'price' => $this->price(),
            'target' => Money::fromSatang($this->targetSatang)->format(),
        ]);
    }
}
