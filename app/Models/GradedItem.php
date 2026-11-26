<?php

namespace App\Models;

use App\Contracts\Purchasable;
use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Enums\OrderStatus;
use App\Exceptions\GradedNotListable;
use App\Support\LinePresentation;
use Database\Factories\GradedItemFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One graded card: a single slab, never a quantity. It is `listed` for sale only with a real photo of the front, the
 * back and the label; a hold flips it to `reserved`, a release back to `listed`, an order to `sold`.
 */
#[Unguarded]
class GradedItem extends Model implements Purchasable
{
    /** @use HasFactory<GradedItemFactory> */
    use HasFactory;

    /** The photo columns, which are paths on the `public` disk. */
    public const PHOTO_COLUMNS = ['front_path', 'back_path', 'label_path'];

    protected static function booted(): void
    {
        // The listing rule lives here so no path can bypass it: a plain `create` from a seeder or a console command
        // is held to it exactly like the admin form. It covers every status the purchasable scope accepts.
        static::saving(function (GradedItem $item): void {
            // The form sends four optional numbers; keep only the ones filled, and none at all as null.
            $item->subgrades = collect($item->subgrades ?? [])->filter(fn (mixed $value): bool => filled($value))->all() ?: null;

            if ($item->isForSaleStatus() && ! $item->meetsListingRule()) {
                throw new GradedNotListable;
            }
        });

        // A replaced photo loses its file, after commit so a rolled-back save never loses it.
        static::updated(function (GradedItem $item): void {
            foreach (self::PHOTO_COLUMNS as $column) {
                $old = $item->getOriginal($column);

                if ($item->wasChanged($column) && filled($old)) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($old));
                }
            }
        });

        static::deleted(function (GradedItem $item): void {
            $paths = collect(self::PHOTO_COLUMNS)->map(fn (string $column) => $item->getRawOriginal($column))->filter()->all();

            DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'grader' => Grader::class,
            'status' => GradedStatus::class,
            'grade' => 'decimal:1',
            'subgrades' => 'array',
            'price_satang' => 'integer',
        ];
    }

    /**
     * Whether the status is one the storefront sells from: `listed`, or `reserved` by a cart.
     */
    public function isForSaleStatus(): bool
    {
        return in_array($this->status, [GradedStatus::Listed, GradedStatus::Reserved], true);
    }

    /**
     * The listing rule: all three photos and a certificate number, so a buyer never meets a slab that cannot be checked.
     */
    public function meetsListingRule(): bool
    {
        return $this->hasAllPhotos() && filled($this->cert_number);
    }

    public function hasAllPhotos(): bool
    {
        return collect(self::PHOTO_COLUMNS)->every(fn (string $column): bool => filled($this->{$column}));
    }

    /**
     * The public URL of one of the three photos, or null when it is not filled.
     */
    public function photoUrl(string $column): ?string
    {
        return filled($this->{$column}) ? Storage::disk('public')->url($this->{$column}) : null;
    }

    /**
     * "PSA 10" or "BGS 9.5": the grader and the grade without a trailing `.0`.
     */
    public function gradeBadge(): string
    {
        return $this->grader->label().' '.rtrim(rtrim((string) $this->grade, '0'), '.');
    }

    /**
     * The certificate number as the order snapshot keeps it: only its last four characters, `•••• 4821`.
     */
    public function maskedCert(): ?string
    {
        if (blank($this->cert_number)) {
            return null;
        }

        return '•••• '.mb_substr($this->cert_number, -4);
    }

    /**
     * The grader's page for this certificate, or null when the number is not filled or the grader has no page.
     */
    public function verifyUrl(): ?string
    {
        return filled($this->cert_number) ? $this->grader->verifyUrl($this->cert_number) : null;
    }

    /**
     * Whether any cart holds it now.
     */
    public function hasLiveHold(): bool
    {
        return $this->liveReservations()->exists();
    }

    public static function lockForHold(int $id): ?static
    {
        return static::query()->lockForUpdate()->find($id);
    }

    public static function purchasableIdsAmong(Collection $ids): Collection
    {
        return static::query()->purchasable()->whereIn('id', $ids)->pluck('id')->flip();
    }

    /**
     * Graded items that can be put in a basket: listed, or reserved by a cart that may be this one, with a price and
     * a printing the storefront shows. Whether another cart holds it is holdableQty()'s question.
     *
     * @param  Builder<GradedItem>  $query
     * @return Builder<GradedItem>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), [GradedStatus::Listed->value, GradedStatus::Reserved->value])
            ->whereNotNull($query->qualifyColumn('price_satang'))
            // Defensive: the saving hook already keeps these filled, a raw write could not.
            ->where(fn (Builder $filled) => collect([...self::PHOTO_COLUMNS, 'cert_number'])->each(
                fn (string $column) => $filled->whereNotNull($query->qualifyColumn($column))->where($query->qualifyColumn($column), '!=', ''),
            ))
            ->whereHas('printing', fn (Builder $printing) => $printing->searchVisible());
    }

    public function isPurchasable(): bool
    {
        return $this->exists && static::query()->purchasable()->whereKey($this->getKey())->exists();
    }

    /**
     * 1 when listed, and 1 when reserved with no live hold (a hold that ran out before the release job flipped it:
     * the row heals itself). Never more than 1, never while a live hold exists.
     */
    public function holdableQty(): int
    {
        if (! $this->isForSaleStatus() || ! $this->meetsListingRule()) {
            return 0;
        }

        return $this->hasLiveHold() ? 0 : 1;
    }

    public function onHandQty(): int
    {
        return in_array($this->status, [GradedStatus::Listed, GradedStatus::Reserved], true) ? 1 : 0;
    }

    public function unitPriceSatang(): int
    {
        return (int) $this->price_satang;
    }

    public function maxPerLine(): int
    {
        return 1;
    }

    public function onHeld(): void
    {
        if ($this->status === GradedStatus::Listed) {
            $this->update(['status' => GradedStatus::Reserved]);
        }
    }

    /**
     * Never throws: a release path runs for many rows, and one bad row must not strand the rest. When the listing rule
     * no longer holds (a photo or the certificate was cleared mid-hold) the item is withdrawn instead, with a warning.
     */
    public function onReleased(): void
    {
        $current = static::query()->lockForUpdate()->find($this->getKey());

        if ($current?->status !== GradedStatus::Reserved || $current->hasLiveHold()) {
            return;
        }

        $current->relistOrWithdraw('release');
        $this->refresh();
    }

    public function onSold(int $qty): void
    {
        $this->update(['status' => GradedStatus::Sold]);
    }

    /**
     * Back on the shelf after a cancel or refund, unless another order that is still alive holds the sale of this slab
     * (it was relisted and sold again meanwhile): then it stays as it is. Without the photos or the certificate it is
     * withdrawn for staff to fix, with a warning, and never throws.
     */
    public function onReturned(int $qty): void
    {
        if ($this->status !== GradedStatus::Sold) {
            return;
        }

        $soldAgain = OrderItem::query()
            ->where('purchasable_type', $this->getMorphClass())
            ->where('purchasable_id', $this->getKey())
            ->whereHas('order', fn (Builder $order) => $order->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value]))
            ->exists();

        if ($soldAgain) {
            return;
        }

        $this->relistOrWithdraw('return');
    }

    private function relistOrWithdraw(string $path): void
    {
        if ($this->meetsListingRule()) {
            $this->update(['status' => GradedStatus::Listed]);

            return;
        }

        Log::warning('A graded item could not be relisted and was withdrawn.', ['graded_item_id' => $this->getKey(), 'path' => $path]);

        $this->update(['status' => GradedStatus::Withdrawn]);
    }

    public function snapshot(): array
    {
        $this->loadMissing(['printing.card', 'printing.set']);
        $printing = $this->printing;

        return [
            'card_name' => $printing->display_name,
            'set_code' => $printing->set->code,
            'number' => $printing->number,
            'language' => $printing->language->value,
            'condition' => null,
            'finish' => null,
            'kind' => 'graded',
            'detail' => $this->gradeBadge().($this->maskedCert() === null ? '' : ' · cert '.$this->maskedCert()),
        ];
    }

    public function cartPresentation(): LinePresentation
    {
        $printing = $this->printing;
        $title = $printing?->card !== null ? $printing->display_name : ($printing?->cardWithTrashed?->name_en ?? '');

        return new LinePresentation(
            title: $title,
            url: $printing?->card !== null ? route('graded.show', $this) : null,
            code: $printing?->display_code,
            language: $printing?->language,
            printing: $printing,
            imageUrl: $this->photoUrl('front_path'),
            gradeBadge: $this->gradeBadge(),
            shape: 'photo',
        );
    }

    public function isInsured(): bool
    {
        return true;
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
}
