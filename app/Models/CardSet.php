<?php

namespace App\Models;

use App\Observers\CardSetSearchObserver;
use Database\Factories\CardSetFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;

/**
 * A card set. Named CardSet because `Set` is awkward in PHP.
 */
#[ObservedBy(CardSetSearchObserver::class)]
#[Table('sets')]
#[Unguarded]
class CardSet extends Model
{
    /** @use HasFactory<CardSetFactory> */
    use HasFactory, Searchable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'card_count' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * The code as shown to customers: upper-case (`OP-05`, `SV3PT5`). URLs keep the stored code.
     *
     * @return Attribute<string, never>
     */
    protected function displayCode(): Attribute
    {
        return Attribute::get(fn (): string => strtoupper($this->code));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_en' => $this->name_en,
            'name_th' => $this->name_th,
            'game' => $this->game->slug,
            'card_count' => $this->card_count,
        ];
    }

    /**
     * `/s/{set:code}`: a code is unique per game, not globally, so the URL resolves the same set every time:
     * the lowest id among the sets of an active game. A code that only inactive games have is not found.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== 'code') {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->newQuery()->searchVisible()->where('code', $value)->orderBy('id')->first();
    }

    /**
     * Set the `in_stock_numbers` and `extra_numbers` attributes on every set in one query, for counts shown
     * against `card_count`. Both count distinct card numbers across all languages, never raw printings:
     * in-stock numbers have an available variant, extra numbers are printed past `card_count` (secret rares).
     * Trashed printings and printings of a trashed card are not counted.
     *
     * @param  Collection<int, CardSet>  $sets
     */
    public static function loadNumberCounts(Collection $sets): void
    {
        $inStock = Variant::query()->available()->selectRaw('1')->whereColumn('variants.printing_id', 'printings.id')->limit(1);

        $numbersBySet = Printing::query()
            ->whereIn('set_id', $sets->modelKeys())
            ->whereHas('card')
            ->select(['set_id', 'number'])
            ->selectSub($inStock, 'in_stock')
            ->get()
            ->groupBy('set_id');

        foreach ($sets as $set) {
            $printings = $numbersBySet->get($set->id, collect());
            $key = fn (Printing $printing): string => (string) (Printing::plainNumber($printing->number) ?? $printing->number);

            $set->setAttribute('in_stock_numbers', $printings->filter(fn (Printing $printing): bool => (bool) $printing->in_stock)->map($key)->unique()->count());
            $set->setAttribute('extra_numbers', $set->card_count > 0
                ? $printings->map(fn (Printing $printing): ?int => Printing::plainNumber($printing->number))->filter(fn (?int $number): bool => $number !== null && $number > $set->card_count)->unique()->count()
                : 0);
        }
    }

    /**
     * A set of an inactive game is not shown.
     */
    public function shouldBeSearchable(): bool
    {
        return (bool) $this->game->is_active;
    }

    /**
     * Sets of an active game, the ones the index shows.
     *
     * @param  Builder<CardSet>  $query
     * @return Builder<CardSet>
     */
    public function scopeSearchVisible(Builder $query): Builder
    {
        return $query->whereHas('game', fn (Builder $game) => $game->where('is_active', true));
    }

    /**
     * Index the sets the query matches that qualify and remove the rest.
     *
     * @param  Builder<CardSet>  $query
     */
    public static function syncSearchIndexFor(Builder $query): void
    {
        if (ModelObserver::syncingDisabledFor(static::class)) {
            return;
        }

        $query->with('game')->chunkById(500, function ($sets): void {
            [$searchable, $removable] = $sets->partition(fn (CardSet $set): bool => $set->shouldBeSearchable());

            $searchable->searchable();
            $removable->unsearchable();
        });
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').'sets';
    }

    /**
     * @param  Builder<CardSet>  $query
     * @return Builder<CardSet>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with('game');
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return HasMany<Printing, $this>
     */
    public function printings(): HasMany
    {
        return $this->hasMany(Printing::class, 'set_id');
    }
}
