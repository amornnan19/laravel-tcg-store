<?php

namespace App\Models;

use App\Contracts\Purchasable;
use App\Enums\SealedKind;
use App\Support\LinePresentation;
use Database\Factories\SealedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;

/**
 * A booster box, pack, starter deck or accessory: stock counted like a variant's (`qty` minus live holds), with its own
 * page. A product marked `is_preorder` is not bought from stock; it is not purchasable here.
 */
#[Unguarded]
class SealedProduct extends Model implements Purchasable
{
    /** @use HasFactory<SealedProductFactory> */
    use HasFactory, Searchable, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (SealedProduct $product): void {
            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug($product->name_en);
            }
        });

        // A replaced image loses its file, after commit so a rolled-back save never loses it.
        static::updated(function (SealedProduct $product): void {
            $old = $product->getOriginal('image_path');

            if ($product->wasChanged('image_path') && filled($old)) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($old));
            }
        });

        // A soft delete keeps the file, since a restore brings the product back.
        static::forceDeleted(function (SealedProduct $product): void {
            $path = $product->getRawOriginal('image_path');

            if (filled($path)) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($path));
            }
        });
    }

    /**
     * Slugify the name (`product` when nothing survives, e.g. a Thai name), then append -2, -3... until no row, trashed or not, owns it.
     */
    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => SealedKind::class,
            'price_satang' => 'integer',
            'qty' => 'integer',
            'is_active' => 'boolean',
            'is_preorder' => 'boolean',
        ];
    }

    /**
     * The name a customer reads: the Thai name when the shop filled one, else the English name.
     */
    public function displayName(): string
    {
        return filled($this->name_th) ? $this->name_th : $this->name_en;
    }

    public function imageUrl(): ?string
    {
        return filled($this->image_path) ? Storage::disk('public')->url($this->image_path) : null;
    }

    public static function lockForHold(int $id): ?static
    {
        // A no-op on SQLite, which serialises writers; a row lock on MySQL and Postgres. A trashed product is not found.
        return static::query()->lockForUpdate()->find($id);
    }

    public static function purchasableIdsAmong(Collection $ids): Collection
    {
        return static::query()->purchasable()->whereIn('id', $ids)->pluck('id')->flip();
    }

    /**
     * Products a customer can put in a basket at all: active, not trashed, priced, not a pre-order, and of a game that
     * is on (a product with no game, such as sleeves, has none to switch off). Stock is scopeAvailable()'s question.
     *
     * @param  Builder<SealedProduct>  $query
     * @return Builder<SealedProduct>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->searchVisible()
            ->whereNotNull($query->qualifyColumn('price_satang'))
            ->where($query->qualifyColumn('is_preorder'), false);
    }

    public function isPurchasable(): bool
    {
        return $this->exists && static::query()->purchasable()->whereKey($this->getKey())->exists();
    }

    /**
     * Products the storefront shows and the index holds: active, not trashed (the soft delete scope), and not of an
     * inactive game.
     *
     * @param  Builder<SealedProduct>  $query
     * @return Builder<SealedProduct>
     */
    public function scopeSearchVisible(Builder $query): Builder
    {
        // Scout hydrates a soft-deleting model withTrashed(), so the trashed rows are excluded here, not left to the global scope.
        return $query->whereNull($query->qualifyColumn('deleted_at'))
            ->where($query->qualifyColumn('is_active'), true)
            ->where(fn (Builder $game) => $game
                ->whereNull($query->qualifyColumn('game_id'))
                ->orWhereHas('game', fn (Builder $games) => $games->where('is_active', true)));
    }

    /**
     * Products with stock a customer can take now: `qty` is greater than what unexpired reservations of any cart hold.
     * Every "in stock" computation goes through this scope or availableQty(), never `qty` alone.
     *
     * @param  Builder<SealedProduct>  $query
     * @return Builder<SealedProduct>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        $model = $query->getModel();

        return $query->whereRaw(
            $model->qualifyColumn('qty').' > (select coalesce(sum(stock_reservations.qty), 0) from stock_reservations'
            .' where stock_reservations.purchasable_type = ? and stock_reservations.purchasable_id = '.$model->getQualifiedKeyName()
            .' and stock_reservations.expires_at > ?)',
            [$model->getMorphClass(), now()->toDateTimeString()],
        );
    }

    /**
     * Load `reserved_qty` (the sum of live reservations) with the products, so availableQty() runs no query per row.
     *
     * @param  Builder<SealedProduct>  $query
     * @return Builder<SealedProduct>
     */
    public function scopeWithReservedQty(Builder $query): Builder
    {
        return $query->withSum('liveReservations as reserved_qty', 'qty');
    }

    /**
     * How many a customer can put in a basket now: `qty` minus unexpired reservations of every cart, never below zero.
     */
    public function availableQty(): int
    {
        return max(0, $this->qty - $this->reservedQty());
    }

    public function isAvailable(): bool
    {
        return $this->availableQty() > 0;
    }

    private function reservedQty(): int
    {
        if (array_key_exists('reserved_qty', $this->attributes)) {
            return (int) $this->attributes['reserved_qty'];
        }

        if ($this->relationLoaded('liveReservations')) {
            return (int) $this->liveReservations->sum('qty');
        }

        return (int) $this->liveReservations()->sum('qty');
    }

    public function holdableQty(): int
    {
        return $this->availableQty();
    }

    public function onHandQty(): int
    {
        return $this->qty;
    }

    public function unitPriceSatang(): int
    {
        return (int) $this->price_satang;
    }

    public function maxPerLine(): int
    {
        return PHP_INT_MAX;
    }

    public function onHeld(): void
    {
        // Counted from its reservations; nothing changes on the row.
    }

    public function onReleased(): void
    {
        // Same: the reservation going away is the release.
    }

    public function onSold(int $qty): void
    {
        $this->qty -= $qty;
        $this->save();
    }

    public function onReturned(int $qty): void
    {
        $this->qty += $qty;
        $this->save();
    }

    public function snapshot(): array
    {
        $this->loadMissing('set');

        return [
            'card_name' => $this->displayName(),
            'set_code' => $this->set?->code ?? '',
            'number' => null,
            'language' => null,
            'condition' => null,
            'finish' => null,
            'kind' => 'sealed',
            'detail' => $this->kind->label().($this->set === null ? '' : ' · '.$this->set->display_code),
        ];
    }

    public function cartPresentation(): LinePresentation
    {
        return new LinePresentation(
            title: $this->displayName(),
            url: $this->trashed() ? null : route('sealed.show', $this),
            code: $this->set === null ? null : $this->set->display_code,
            badges: [$this->kind->label()],
            imageUrl: $this->imageUrl(),
            shape: 'square',
        );
    }

    public function isInsured(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name_en' => $this->name_en,
            'name_th' => $this->name_th,
            'sku' => $this->sku,
            'game' => $this->game?->slug,
            'set_code' => $this->set?->code,
        ];
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').'sealed_products';
    }

    /**
     * Only what the storefront shows is indexed.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->is_active && ! $this->trashed() && ($this->game === null || $this->game->is_active);
    }

    /**
     * @param  Builder<SealedProduct>  $query
     * @return Builder<SealedProduct>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(['game', 'set']);
    }

    /**
     * Index the products the query matches that qualify and remove the rest.
     *
     * @param  Builder<SealedProduct>  $query
     */
    public static function syncSearchIndexFor(Builder $query): void
    {
        if (ModelObserver::syncingDisabledFor(static::class)) {
            return;
        }

        $query->with(['game', 'set'])->chunkById(500, function ($products): void {
            [$searchable, $removable] = $products->partition(fn (SealedProduct $product): bool => $product->shouldBeSearchable());

            $searchable->searchable();
            $removable->unsearchable();
        });
    }

    /**
     * Reservations that still hold it, from any cart.
     *
     * @return MorphMany<StockReservation, $this>
     */
    public function liveReservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'purchasable')->live();
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<CardSet, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(CardSet::class, 'set_id');
    }
}
