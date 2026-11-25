<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent once when a variant the customer asked about can be bought again.
 */
class RestockAlertFired extends VariantAlertFired
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.restock_alert.subject', ['card' => $this->cardName]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->text())
            ->action(__('notifications.restock_alert.action'), $this->cardUrl)
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')]));
    }

    public function toLine(object $notifiable): string
    {
        return $this->text()."\n".$this->cardUrl;
    }

    private function text(): string
    {
        return __('notifications.restock_alert.line_text', [
            'card' => $this->cardName,
            'meta' => $this->meta,
            'price' => $this->price(),
        ]);
    }
}
