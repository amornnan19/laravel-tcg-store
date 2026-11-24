<?php

namespace App\Models;

use App\Observers\CardSetSearchObserver;
use Database\Factories\CardSetFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
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
