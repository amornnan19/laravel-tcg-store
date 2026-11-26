<?php

namespace App\Models;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use Database\Factories\BuylistSubmissionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One line of a submission. The card, code, language, claimed condition and quoted unit are copies taken when the list
 * was sent. The `assessed_*` columns and the photo are what the shop found when the cards arrived.
 */
class BuylistSubmissionItem extends Model
{
    /** @use HasFactory<BuylistSubmissionItemFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id', 'printing_id', 'buylist_price_id', 'card_name', 'display_code', 'language', 'finish',
        'claimed_condition', 'qty', 'quoted_unit_satang', 'quoted_line_satang', 'assessed_condition',
        'assessed_unit_satang', 'assessed_line_satang', 'assessed_note', 'photo_path',
    ];

    protected static function booted(): void
    {
        // A replaced or removed photo loses its file after commit, so a rolled-back save never loses it.
        static::saved(function (BuylistSubmissionItem $item): void {
            $old = $item->getOriginal('photo_path');

            if ($item->wasChanged('photo_path') && filled($old)) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($old));
            }
        });

        static::deleted(function (BuylistSubmissionItem $item): void {
            if (filled($item->photo_path)) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($item->photo_path));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'language' => Language::class,
            'finish' => Finish::class,
            'claimed_condition' => CardCondition::class,
            'assessed_condition' => CardCondition::class,
            'qty' => 'integer',
            'quoted_unit_satang' => 'integer',
            'quoted_line_satang' => 'integer',
            'assessed_unit_satang' => 'integer',
            'assessed_line_satang' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BuylistSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(BuylistSubmission::class, 'submission_id');
    }

    /**
     * @return BelongsTo<Printing, $this>
     */
    public function printing(): BelongsTo
    {
        return $this->belongsTo(Printing::class)->withTrashed();
    }

    /**
     * @return BelongsTo<BuylistPrice, $this>
     */
    public function buylistPrice(): BelongsTo
    {
        return $this->belongsTo(BuylistPrice::class);
    }
}
