<?php

namespace App\Models;

use App\Enums\BuylistPayout;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Support\Money;
use Database\Factories\BuylistPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the shop will pay for one printing in one condition and finish, in cash and in store credit. A printing with no
 * row, or an inactive one, is simply not bought. `accepted_this_week` is the quantity committed to submissions since
 * Monday (`ResetBuylistQuotas` clears it), written only by the buylist actions.
 */
class BuylistPrice extends Model
{
    /** @use HasFactory<BuylistPriceFactory> */
    use HasFactory;

    protected $fillable = [
        'printing_id', 'condition', 'finish', 'cash_satang', 'credit_satang', 'weekly_quota', 'is_active',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'condition' => CardCondition::class,
            'finish' => Finish::class,
            'cash_satang' => 'integer',
            'credit_satang' => 'integer',
            'weekly_quota' => 'integer',
            'accepted_this_week' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Rows the shop buys now: active, the printing is visible in the storefront (not trashed, card and game live) and
     * the cash price is above zero.
     *
     * @param  Builder<BuylistPrice>  $query
     * @return Builder<BuylistPrice>
     */
    public function scopeBuyable(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where($query->qualifyColumn('cash_satang'), '>', 0)
            ->whereHas('printing', fn (Builder $printing) => $printing->searchVisible());
    }

    /**
     * The credit that matches a cash price: cash plus the bonus, in whole baht (halves round up), never above the
     * largest price.
     */
    public static function creditFor(int $cashSatang, ?int $bonusBasisPoints = null): int
    {
        $bonus = $bonusBasisPoints ?? (int) config('buylist.credit_bonus_bp');
        $satang = intdiv($cashSatang * (10000 + $bonus) + 5000, 10000);

        return min(intdiv($satang + 50, 100) * 100, intdiv(Money::MAX_SATANG, 100) * 100);
    }

    /**
     * What one card is quoted at under a payout.
     */
    public function unitFor(BuylistPayout $payout): int
    {
        return $payout === BuylistPayout::Credit ? $this->credit_satang : $this->cash_satang;
    }

    /**
     * How many more the shop takes this week, never below zero; null when there is no cap.
     */
    public function remainingQuota(): ?int
    {
        if ($this->weekly_quota === null) {
            return null;
        }

        return max(0, $this->weekly_quota - $this->accepted_this_week);
    }

    /**
     * What staff call this row in a message: set code, number, card, language, condition and finish. Reads the trashed
     * card too, so it always renders.
     */
    public function label(): string
    {
        $printing = $this->printing->loadMissing(['set', 'cardWithTrashed']);

        return $printing->set->code.' '.$printing->number.' '.$printing->cardWithTrashed?->name_en.' · '.$printing->language->shortCode()
            .' · '.$this->condition->label().' · '.$this->finish->label();
    }

    /**
     * The printing, trashed or not, so admin lists and a submitted line never lose their card.
     *
     * @return BelongsTo<Printing, $this>
     */
    public function printing(): BelongsTo
    {
        return $this->belongsTo(Printing::class)->withTrashed();
    }
}
