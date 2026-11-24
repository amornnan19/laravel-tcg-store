<?php

namespace App\Models;

use App\Enums\CardCondition;
use App\Enums\Language;
use Database\Factories\PrintingFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Unguarded]
class Printing extends Model
{
    /** @use HasFactory<PrintingFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'language' => Language::class,
            'has_foil' => 'boolean',
            'released_on' => 'date',
        ];
    }

    /**
     * @param  Builder<Printing>  $query
     * @return Builder<Printing>
     */
    public function scopeForLanguage(Builder $query, Language $language): Builder
    {
        return $query->where('language', $language->value);
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * The card including a trashed one, for admin titles, labels and search only: the storefront and
     * search index must keep using card().
     *
     * @return BelongsTo<Card, $this>
     */
    public function cardWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'card_id')->withTrashed();
    }

    /**
     * @return BelongsTo<CardSet, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(CardSet::class, 'set_id');
    }

    /**
     * Ordered best condition first (NM to DMG), normal before foil; the admin repeater relies on it.
     *
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        $conditionOrder = collect(CardCondition::cases())
            ->map(fn (CardCondition $condition): string => "when '{$condition->value}' then {$condition->sort()}")
            ->implode(' ');

        return $this->hasMany(Variant::class)
            ->orderByRaw("case variants.condition {$conditionOrder} else 99 end")
            ->orderByRaw("case variants.finish when 'normal' then 1 else 2 end");
    }
}
