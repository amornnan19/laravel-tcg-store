<?php

namespace App\Models;

use App\Enums\AlertChannel;
use Database\Factories\PriceAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tells a customer once when a variant's price reaches `target_satang`, then deactivates itself.
 */
class PriceAlert extends Model
{
    /** @use HasFactory<PriceAlertFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'variant_id', 'target_satang', 'channel', 'is_active', 'last_fired_at'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'target_satang' => 'integer',
            'channel' => AlertChannel::class,
            'is_active' => 'boolean',
            'last_fired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
