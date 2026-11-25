<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of an order: a snapshot of what was bought (names, codes, price) as plain strings, so it reads
 * correctly whatever later happens to the card or the variant. `kind` says which shape it has: a `single` has a
 * number, language, condition and finish; a `graded` or `sealed` line leaves them null and carries `detail`
 * ("PSA 10 · cert •••• 4821", "Booster Box") instead. `purchasable` is a convenience link that may be null.
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id', 'purchasable_type', 'purchasable_id', 'card_name', 'set_code', 'number', 'language', 'condition',
        'finish', 'kind', 'detail', 'unit_price_satang', 'qty', 'line_total_satang',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_satang' => 'integer',
            'qty' => 'integer',
            'line_total_satang' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
