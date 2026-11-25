<?php

namespace App\Models;

use App\Enums\AlertChannel;
use Database\Factories\RestockAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tells a customer once when a sold-out variant can be bought again, then deactivates itself.
 */
class RestockAlert extends Model
{
    /** @use HasFactory<RestockAlertFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'variant_id', 'channel', 'is_active', 'notified_at'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'channel' => AlertChannel::class,
            'is_active' => 'boolean',
            'notified_at' => 'datetime',
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
