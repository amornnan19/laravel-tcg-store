<?php

namespace App\Notifications\Channels;

use App\Notifications\ShopNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pushes a notification's LINE text to the notifiable's LINE user id through the Messaging API.
 *
 * A retry sends the same `X-Line-Retry-Key`, so LINE delivers a message once even when the first response was lost.
 */
class LineChannel
{
    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    /**
     * @throws RequestException when LINE answers 429 or 5xx, so the queue retries.
     * @throws ConnectionException when LINE cannot be reached, so the queue retries.
     */
    public function send(object $notifiable, ShopNotification $notification): void
    {
        $to = $notifiable->routeNotificationFor('line', $notification);

        if ($to === null) {
            // The customer unlinked LINE after the message was queued.
            $notification->sendFallbackMail($notifiable);

            return;
        }

        $response = Http::withToken((string) config('services.line.messaging_token'))
            ->withHeaders(['X-Line-Retry-Key' => (string) ($notification->id ?? Str::uuid())])
            ->timeout(10)
            ->post(self::PUSH_URL, [
                'to' => $to,
                'messages' => [['type' => 'text', 'text' => $notification->toLine($notifiable)]],
            ]);

        // 409: LINE already accepted this retry key, the message is on its way.
        if ($response->successful() || $response->status() === 409) {
            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        // Any other 4xx will not change on a retry (a user who blocked the shop, a bad token): use email.
        Log::warning('LINE push refused, falling back to email.', ['status' => $response->status(), 'body' => $response->body()]);

        $notification->sendFallbackMail($notifiable);
    }
}
