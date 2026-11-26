<?php

namespace App\Models;

use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use Database\Factories\BuylistSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's list of cards to sell the shop, as quoted when they sent it: the items snapshot the card, the condition
 * the customer claimed and the unit price quoted, so editing a price or a card never rewrites a submission. The status
 * timeline reads the `*_at` columns.
 */
class BuylistSubmission extends Model
{
    /** @use HasFactory<BuylistSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'user_id', 'payout', 'status', 'quoted_total_satang', 'final_total_satang', 'quote_expires_at',
        'received_at', 'revised_at', 'responded_at', 'accepted_at', 'paid_at', 'returned_at', 'quota_released_at', 'late_note',
        'payout_reference', 'staff_note', 'return_tracking',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'payout' => BuylistPayout::class,
            'status' => BuylistStatus::class,
            'quoted_total_satang' => 'integer',
            'final_total_satang' => 'integer',
            'quote_expires_at' => 'datetime',
            'received_at' => 'datetime',
            'revised_at' => 'datetime',
            'responded_at' => 'datetime',
            'accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'returned_at' => 'datetime',
            'quota_released_at' => 'datetime',
        ];
    }

    /**
     * The human-facing number, `BUY-00042`, from the row id.
     */
    public static function numberFor(int $id): string
    {
        return sprintf('BUY-%05d', $id);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /**
     * The total the customer is looking at: what the shop found once it has checked, else what it quoted.
     */
    public function totalSatang(): int
    {
        return $this->final_total_satang ?? $this->quoted_total_satang;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BuylistSubmissionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BuylistSubmissionItem::class, 'submission_id')->orderBy('id');
    }
}
