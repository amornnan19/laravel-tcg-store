<?php

namespace App\Models;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Observers\VariantSearchObserver;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[ObservedBy(VariantSearchObserver::class)]
#[Unguarded]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Delete photos through Eloquent so each one removes its file; the FK cascade alone would orphan them.
        static::deleting(function (Variant $variant): void {
            $variant->photos()->get()->each->delete();
        });

        static::updating(VariantSearchObserver::rememberPreviousPrinting(...));

        static::creating(function (Variant $variant): void {
            if (blank($variant->sku)) {
                $variant->sku = static::makeSku($variant);
            }
        });
    }

    /**
     * `{set.code}-{number}-{language}-{condition}-{finish}`, upper-cased, with every character
     * outside `[A-Z0-9-]` replaced by `_`. Example: `OP05-119-JA-NM-F`. A taken SKU gets `-2`, `-3`...
     */
    protected static function makeSku(Variant $variant): string
    {
        $printing = Printing::withTrashed()->with('set')->find($variant->printing_id);

        if ($printing === null) {
            throw new LogicException("Cannot generate a SKU: printing [{$variant->printing_id}] does not exist.");
        }

        $sku = strtoupper(implode('-', [
            $printing->set->code,
            $printing->number,
            $printing->language->value,
            $variant->condition->value,
            $variant->finish->skuCode(),
        ]));

        $base = preg_replace('/[^A-Z0-9-]/', '_', $sku);
        $sku = $base;
        $suffix = 2;

        while (static::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$suffix++;
        }

        return $sku;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'condition' => CardCondition::class,
            'finish' => Finish::class,
            'price_satang' => 'integer',
            'compare_at_satang' => 'integer',
            'qty' => 'integer',
            'has_real_photo' => 'boolean',
        ];
    }

    /**
     * Variants a customer can buy now: `qty` is greater than what unexpired reservations of any cart hold. Every
     * "available" or "in stock" computation must go through this scope or isAvailable(), never `qty` alone.
     *
     * The sum is a correlated subquery, so it works in `whereHas('variants', ...)` and `Variant::query()`.
     *
     * @param  Builder<Variant>  $query
     * @return Builder<Variant>
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
     * Variants that can be put in a basket at all: a price, and a printing the storefront shows (card not trashed,
     * game active). The one purchasability rule: every path that creates or increases a hold goes through it, and the
     * cart page treats a line that fails it as gone. Stock is a separate question (scopeAvailable()).
     *
     * @param  Builder<Variant>  $query
     * @return Builder<Variant>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->qualifyColumn('price_satang'))
            ->whereHas('printing', fn (Builder $printing) => $printing->searchVisible());
    }

    /**
     * Whether this variant, as the database has it now, passes scopePurchasable().
     */
    public function isPurchasable(): bool
    {
        return $this->exists && static::query()->purchasable()->whereKey($this->getKey())->exists();
    }

    /**
     * Load `reserved_qty` (the sum of live reservations) with the variants, so availableQty() and isAvailable()
     * run no query per variant. Every storefront query that loads variants for display uses it.
     *
     * @param  Builder<Variant>  $query
     * @return Builder<Variant>
     */
    public function scopeWithReservedQty(Builder $query): Builder
    {
        return $query->withSum('liveReservations as reserved_qty', 'qty');
    }

    /**
     * The in-memory twin of scopeAvailable(), for variants that are already loaded.
     */
    public function isAvailable(): bool
    {
        return $this->availableQty() > 0;
    }

    /**
     * How many a customer can put in a basket now: `qty` minus unexpired reservations of every cart, never below
     * zero. The quantity stepper's cap reads this and never `qty`. Reads `reserved_qty` when the query loaded it
     * (withReservedQty()), else the loaded `liveReservations`, else runs one query.
     */
    public function availableQty(): int
    {
        return max(0, $this->qty - $this->reservedQty());
    }

    /**
     * "NM · ฟอยล์": the condition code and the finish, how a cart line names the variant it holds.
     */
    public function conditionFinishLabel(): string
    {
        return strtoupper($this->condition->value).' · '.$this->finish->label();
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

    /**
     * Reservations that still hold stock, from any cart.
     *
     * @return MorphMany<StockReservation, $this>
     */
    public function liveReservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'purchasable')->live();
    }

    /**
     * @return BelongsTo<Printing, $this>
     */
    public function printing(): BelongsTo
    {
        return $this->belongsTo(Printing::class);
    }

    /**
     * The printing including a trashed one, for admin lists and titles only.
     *
     * @return BelongsTo<Printing, $this>
     */
    public function printingWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Printing::class, 'printing_id')->withTrashed();
    }

    /**
     * @return HasMany<VariantPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(VariantPhoto::class);
    }

    /**
     * @return HasMany<PricePoint, $this>
     */
    public function pricePoints(): HasMany
    {
        return $this->hasMany(PricePoint::class);
    }
}
