<?php

namespace App\Actions;

use App\Actions\Concerns\ResolvesAlertChannel;
use App\Exceptions\AlertRefused;
use App\Models\RestockAlert;
use App\Models\User;
use App\Models\Variant;

/**
 * Asks to be told when sold-out variants can be bought again. Takes many ids because the deck-list importer sets a
 * whole list at once.
 */
class SetRestockAlerts
{
    use ResolvesAlertChannel;

    /**
     * Only a variant that can be bought at all (price, visible printing) and has nothing available gets an alert; the
     * rest are skipped. An alert that already fired is woken again.
     *
     * @param  array<int, int>  $variantIds
     * @return int How many alerts are set.
     *
     * @throws AlertRefused `no_channel`.
     */
    public function handle(User $user, array $variantIds): int
    {
        $channel = $this->channelFor($user);

        $eligible = Variant::query()
            ->whereIn('id', $variantIds)
            ->purchasable()
            ->withReservedQty()
            ->get()
            ->reject(fn (Variant $variant): bool => $variant->isAvailable());

        if ($eligible->isEmpty()) {
            return 0;
        }

        RestockAlert::query()->upsert(
            $eligible->map(fn (Variant $variant): array => [
                'user_id' => $user->id,
                'variant_id' => $variant->id,
                'channel' => $channel->value,
                'is_active' => true,
                'notified_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
            ['user_id', 'variant_id'],
            ['channel', 'is_active', 'notified_at', 'updated_at'],
        );

        return $eligible->count();
    }
}
