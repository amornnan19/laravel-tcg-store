<?php

namespace App\Models;

use App\Enums\StoreCreditReason;
use Database\Factories\StoreCreditEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One line of a customer's store-credit ledger. Append-only: a mistake is corrected by a new entry, never by editing
 * or deleting one. Written only by `RecordStoreCredit`, which also keeps `users.store_credit_satang` in step.
 */
class StoreCreditEntry extends Model
{
    /** @use HasFactory<StoreCreditEntryFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'delta_satang', 'reason', 'source_type', 'source_id', 'note', 'balance_after_satang', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A store credit entry cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A store credit entry cannot be deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'reason' => StoreCreditReason::class,
            'delta_satang' => 'integer',
            'balance_after_satang' => 'integer',
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
     * The staff member who made an adjustment.
     *
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The order or submission this entry is about, when it has one.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
