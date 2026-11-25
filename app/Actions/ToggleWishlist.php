<?php

namespace App\Actions;

use App\Models\Printing;
use App\Models\User;
use App\Models\WishlistItem;

/**
 * Puts a printing on the customer's wishlist, or takes it off when it is already there.
 */
class ToggleWishlist
{
    /**
     * @return bool Whether the printing is on the wishlist now.
     */
    public function handle(User $user, Printing $printing): bool
    {
        $removed = WishlistItem::query()
            ->where('user_id', $user->id)
            ->where('printing_id', $printing->id)
            ->delete();

        if ($removed > 0) {
            return false;
        }

        // A double click lands on the unique key; either way the printing is on the list.
        WishlistItem::query()->upsert(
            [['user_id' => $user->id, 'printing_id' => $printing->id, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'printing_id'],
            ['updated_at'],
        );

        return true;
    }
}
