<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a cart. `unit_price_satang` is the price when the line was added (or last re-locked after its hold
 * expired), so a price edit never silently changes a cart that is holding stock.
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'purchasable_type', 'purchasable_id', 'qty', 'unit_price_satang'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price_satang' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The hold this line has on stock, expired or not. It is keyed on the line's own purchasable, so it can be
     * read from a loaded line but not eager loaded; to load many, read `Cart::reservations` and match.
     *
     * @return HasOne<StockReservation, $this>
     */
    public function reservation(): HasOne
    {
        return $this->hasOne(StockReservation::class, 'cart_id', 'cart_id')
            ->where('purchasable_type', $this->purchasable_type)
            ->where('purchasable_id', $this->purchasable_id);
    }
}
