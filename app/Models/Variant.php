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
     * Variants a customer can buy now. Today that is `qty > 0`; M4 subtracts unexpired stock reservations
     * here, so every "available" or "in stock" computation must go through this scope or isAvailable().
     *
     * @param  Builder<Variant>  $query
     * @return Builder<Variant>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('qty', '>', 0);
    }

    /**
     * The in-memory twin of scopeAvailable(), for variants that are already loaded.
     */
    public function isAvailable(): bool
    {
        return $this->qty > 0;
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
