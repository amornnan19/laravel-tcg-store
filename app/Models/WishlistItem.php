<?php

namespace App\Models;

use Database\Factories\WishlistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A printing a customer wants to keep an eye on. Never fires anything: it is a list.
 */
class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'printing_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Including a trashed printing: the list tolerates a card that left the shop, and the account page filters it.
     *
     * @return BelongsTo<Printing, $this>
     */
    public function printing(): BelongsTo
    {
        return $this->belongsTo(Printing::class)->withTrashed();
    }
}
