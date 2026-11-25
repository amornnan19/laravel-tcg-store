<?php

namespace App\Models;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use Database\Factories\PrintingFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;

#[Unguarded]
class Printing extends Model
{
    /** @use HasFactory<PrintingFactory> */
    use HasFactory, Searchable, SoftDeletes;

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
     * The code printed on the card, as shown in lists: the set code without its dash, upper-case, then the number.
     *
     * @return Attribute<string, never>
     */
    protected function displayCode(): Attribute
    {
        return Attribute::get(fn (): string => strtoupper(str_replace('-', '', $this->set->code)).'-'.$this->number);
    }

    /**
     * The card name in the printing's own language, falling back to English.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->language) {
            Language::Ja => $this->card->name_ja,
            Language::Th => $this->card->name_th,
            default => null,
        } ?: $this->card->name_en);
    }

    /**
     * Whether a foil copy can be bought now. `has_foil` only says the printing exists in foil.
     */
    public function hasAvailableFoil(): bool
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();

        return $variants->contains(fn (Variant $variant): bool => $variant->finish === Finish::Foil && $variant->isAvailable());
    }

    /**
     * Printings a storefront grid shows as tiles, with everything a tile reads loaded in three queries
     * besides this one (card, set, variants) however many tiles there are.
     *
     * @param  Builder<Printing>  $query
     * @return Builder<Printing>
     */
    public function scopeWithCheapestVariant(Builder $query): Builder
    {
        return $query->with(['card', 'set', 'variants']);
    }

    /**
     * Printings the index would show: the card is not trashed and the game is active (a trashed printing is
     * already excluded). Every search jump and fallback query uses it.
     *
     * @param  Builder<Printing>  $query
     * @return Builder<Printing>
     */
    public function scopeSearchVisible(Builder $query): Builder
    {
        return $query->whereHas('card')->whereHas('set.game', fn (Builder $game) => $game->where('is_active', true));
    }

    /**
     * The database stand-in for a text search, used when the engine is down: a card name in any script or a set
     * code contains the text.
     *
     * @param  Builder<Printing>  $query
     * @return Builder<Printing>
     */
    public function scopeMatchingText(Builder $query, string $text): Builder
    {
        $term = '%'.$text.'%';

        return $query->where(function (Builder $builder) use ($term): void {
            $builder->whereHas('card', fn (Builder $card) => $card
                ->whereLike('name_en', $term)->orWhereLike('name_ja', $term)->orWhereLike('name_th', $term))
                ->orWhereHas('set', fn (Builder $set) => $set->whereLike('code', $term));
        });
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
     * The integer of the digits before any `/`: `062` is 62, `215/197` is 215. Null when there are none.
     */
    public static function plainNumber(string $number): ?int
    {
        $digits = preg_replace('/\D/', '', strtok($number, '/') ?: '');

        return $digits === '' || $digits === null ? null : (int) $digits;
    }

    /**
     * Stock facts derived from the variants, the one place they are computed (search index and results).
     * Uses the loaded `variants` relation when there is one, so a bulk import runs no per-printing query.
     *
     * `from_satang` is the lowest price of an available variant; `from_satang_any` the lowest price of any variant,
     * sold out or not, so a sold-out tile can still show what it cost.
     *
     * @return array{in_stock: bool, available_qty: int, from_satang: ?int, from_satang_any: ?int, conditions: list<string>, has_real_photo: bool}
     */
    public function stockSummary(): array
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();
        $available = $variants->filter(fn (Variant $variant): bool => $variant->isAvailable());

        return [
            'in_stock' => $available->isNotEmpty(),
            'available_qty' => (int) $available->sum('qty'),
            'from_satang' => $available->whereNotNull('price_satang')->min('price_satang'),
            'from_satang_any' => $variants->whereNotNull('price_satang')->min('price_satang'),
            'conditions' => $available->map(fn (Variant $variant): string => $variant->condition->value)->unique()->values()->all(),
            'has_real_photo' => $variants->contains(fn (Variant $variant): bool => $variant->has_real_photo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $stock = $this->stockSummary();

        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'set_id' => $this->set_id,
            'name_en' => $this->card->name_en,
            'name_ja' => $this->card->name_ja,
            'name_th' => $this->card->name_th,
            'set_code' => $this->set->code,
            'set_name' => $this->set->name_en,
            'number' => $this->number,
            'number_plain' => static::plainNumber($this->number),
            'game' => $this->set->game->slug,
            'rarity' => $this->rarity_code,
            'language' => $this->language->value,
            'card_type' => $this->card->card_type,
            'traits' => $this->card->traits,
            'has_foil' => $this->has_foil,
            'in_stock' => $stock['in_stock'],
            'available_qty' => $stock['available_qty'],
            'has_real_photo' => $stock['has_real_photo'],
            'conditions' => $stock['conditions'],
            'from_satang' => $stock['from_satang'],
            'from_satang_any' => $stock['from_satang_any'],
            'image_path' => $this->image_path,
            'released_on' => ($this->released_on ?? $this->set->released_on)?->toDateString(),
        ];
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').'printings';
    }

    /**
     * Not searchable when the printing or its card is trashed, or its game is inactive. `card` is the
     * relation that excludes a trashed card, so a null card means trashed.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed()
            && $this->card !== null
            && (bool) $this->set->game->is_active;
    }

    /**
     * @param  Builder<Printing>  $query
     * @return Builder<Printing>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(['card', 'set.game', 'variants']);
    }

    /**
     * Bring the index in line with the database for every printing the query matches: index the ones that
     * qualify and remove the rest. Works in chunks, so a whole set can be re-indexed without a query per printing.
     *
     * @param  Builder<Printing>  $query
     */
    public static function syncSearchIndexFor(Builder $query): void
    {
        // A seeder or import that runs inside withoutSyncingToSearch() indexes everything once at the end.
        if (ModelObserver::syncingDisabledFor(static::class)) {
            return;
        }

        $query->withTrashed()->with(['card', 'set.game', 'variants'])->chunkById(500, function ($printings): void {
            [$searchable, $removable] = $printings->partition(fn (Printing $printing): bool => $printing->shouldBeSearchable());

            $searchable->searchable();
            $removable->unsearchable();
        });
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
