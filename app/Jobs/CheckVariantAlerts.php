<?php

namespace App\Jobs;

use App\Models\PriceAlert;
use App\Models\RestockAlert;
use App\Models\Variant;
use App\Notifications\PriceAlertFired;
use App\Notifications\RestockAlertFired;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the customers waiting on a variant that it can be bought, once. Dispatched wherever the variant's stock or price
 * may have changed; it reads the variant as it is now, so a late or repeated run is harmless.
 *
 * Idempotent by construction: each alert is claimed with a conditional update (`is_active` true to false), and only the
 * run that changed the row notifies. It calls no external API, so one attempt is enough; the notification it queues
 * retries and falls back to email on its own.
 */
class CheckVariantAlerts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds a waiting check of one variant swallows further dispatches for it. The lock is released when the job
     * starts, so a change committed while it runs gets a job of its own.
     */
    public int $uniqueFor = 10;

    public function __construct(public int $variantId) {}

    public function uniqueId(): string
    {
        return (string) $this->variantId;
    }

    public function handle(): void
    {
        $variant = Variant::query()
            ->purchasable()
            ->withReservedQty()
            ->with(['printing.card', 'printing.set'])
            ->find($this->variantId);

        if ($variant === null || ! $variant->isAvailable()) {
            return;
        }

        RestockAlert::query()
            ->where('variant_id', $variant->id)
            ->where('is_active', true)
            ->with('user')
            ->chunkById(100, function (Collection $alerts) use ($variant): void {
                foreach ($alerts as $alert) {
                    $claimed = RestockAlert::query()
                        ->whereKey($alert->id)
                        ->where('is_active', true)
                        ->update(['is_active' => false, 'notified_at' => now()]);

                    if ($claimed === 1 && $alert->user !== null) {
                        $alert->user->notify(new RestockAlertFired($variant, $alert->user->email));
                    }
                }
            });

        PriceAlert::query()
            ->where('variant_id', $variant->id)
            ->where('is_active', true)
            ->where('target_satang', '>=', $variant->price_satang)
            ->with('user')
            ->chunkById(100, function (Collection $alerts) use ($variant): void {
                foreach ($alerts as $alert) {
                    $claimed = PriceAlert::query()
                        ->whereKey($alert->id)
                        ->where('is_active', true)
                        ->update(['is_active' => false, 'last_fired_at' => now()]);

                    if ($claimed === 1 && $alert->user !== null) {
                        $alert->user->notify(new PriceAlertFired($variant, $alert->target_satang, $alert->user->email));
                    }
                }
            });
    }
}
