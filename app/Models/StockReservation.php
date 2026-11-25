<?php

namespace App\Models;

use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Stock a cart is holding until `expires_at`. One row per cart line. An expired row holds nothing, whether or not
 * the release job has deleted it yet: availability always filters on `expires_at`.
 */
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'purchasable_type', 'purchasable_id', 'qty', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Reservations that still hold stock.
     *
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('expires_at'), '>', now());
    }

    public function isLive(): bool
    {
        return $this->expires_at->isFuture();
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
}
