<?php

namespace App\Jobs;

use App\Actions\RequestPreorderBalance;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Seven days before a release, asks every deposit pre-order that has its deposit in for the balance: a pending payment for
 * `balance_due_satang` due by the end of the release day (or a payment window from now, if that is later), and a message with the link back to the same order. Each order
 * is claimed under its row lock by stamping `balance_requested_at`, so a second run, or a retry after a failure part way,
 * never asks twice. One order failing is reported and does not stop the rest.
 */
class RequestPreorderBalances implements ShouldQueue
{
    use Queueable;

    /**
     * A retry is safe: an order already asked is skipped.
     */
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $requestBalance = app(RequestPreorderBalance::class);

        $horizon = now(config('shop.timezone'))->addDays(7)->toDateString();

        Order::query()
            ->where('kind', OrderKind::Preorder->value)
            ->where('status', OrderStatus::AwaitingBalance->value)
            ->whereNull('balance_requested_at')
            ->whereHas('preorder', fn ($preorders) => $preorders->whereDate('release_on', '<=', $horizon))
            ->lazyById()
            ->each(function (Order $order) use ($requestBalance): void {
                try {
                    $requestBalance->handle($order);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
