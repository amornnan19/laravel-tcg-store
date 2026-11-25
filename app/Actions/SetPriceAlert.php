<?php

namespace App\Actions;

use App\Actions\Concerns\ResolvesAlertChannel;
use App\Exceptions\AlertRefused;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;

/**
 * Watches a variant's price for the customer. Setting it again updates the target and wakes an alert that already fired.
 */
class SetPriceAlert
{
    use ResolvesAlertChannel;

    /**
     * @throws AlertRefused `invalid_target`, `unavailable`, `price_reached` or `no_channel`.
     */
    public function handle(User $user, Variant $variant, int $targetSatang): PriceAlert
    {
        if ($targetSatang <= 0) {
            throw new AlertRefused('invalid_target');
        }

        if (! $variant->isPurchasable()) {
            throw new AlertRefused('unavailable');
        }

        $current = Variant::query()->available()->whereKey($variant->id)->value('price_satang');

        // Already at or under the target and on sale: it would fire at once, so there is nothing to wait for.
        if ($current !== null && (int) $current <= $targetSatang) {
            throw new AlertRefused('price_reached', ['price' => Money::fromSatang((int) $current)->format()]);
        }

        $channel = $this->channelFor($user);

        PriceAlert::query()->upsert(
            [[
                'user_id' => $user->id,
                'variant_id' => $variant->id,
                'target_satang' => $targetSatang,
                'channel' => $channel->value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'variant_id'],
            ['target_satang', 'channel', 'is_active', 'updated_at'],
        );

        return PriceAlert::query()->where('user_id', $user->id)->where('variant_id', $variant->id)->firstOrFail();
    }
}
