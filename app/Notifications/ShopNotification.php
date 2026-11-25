<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * The base of every message the shop sends a customer. It routes by who is being notified: LINE when the customer
 * linked it and opted in, else email when there is an address, else nothing. A subclass writes the mail and the LINE
 * text and never picks a channel itself.
 *
 * A LINE push that cannot be delivered falls back to the same message by email, never to nothing.
 */
abstract class ShopNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Queued notifications get one attempt by default; a push to LINE is retried.
     */
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User && $notifiable->prefersLine()) {
            return ['line'];
        }

        if (filled($notifiable->routeNotificationFor('mail', $this))) {
            return ['mail'];
        }

        return [];
    }

    /**
     * The LINE message: short, ending with the link on its own line.
     */
    abstract public function toLine(object $notifiable): string;

    /**
     * Where this message goes by email when LINE cannot deliver it. `failed()` runs on the queued job and has no
     * notifiable, so a notification that must survive a failed push returns an address it carries itself (an order's
     * contact email) and ignores the argument.
     */
    public function fallbackMailRoute(?object $notifiable): ?string
    {
        $route = $notifiable?->routeNotificationFor('mail', $this);

        return filled($route) ? (string) $route : null;
    }

    /**
     * Sends this same notification by email, now: it is not queued again and cannot go back to LINE.
     */
    public function sendFallbackMail(?object $notifiable): void
    {
        $address = $this->fallbackMailRoute($notifiable);

        if (blank($address)) {
            return;
        }

        NotificationFacade::sendNow(NotificationFacade::route('mail', $address), $this, ['mail']);
    }

    /**
     * Called when the queue gives up. Only a failed LINE push is rescued by email: any other failure (a mail transport
     * error) is left failed, so a broken mail is not sent a second time.
     */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof HttpClientException) {
            $this->sendFallbackMail(null);
        }
    }
}
