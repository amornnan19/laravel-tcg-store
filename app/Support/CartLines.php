<?php

namespace App\Support;

use App\Contracts\Purchasable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\GradedItem;
use App\Models\StockReservation;
use App\Models\Variant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * What a cart's lines are, read once: each line with its printing, the variant's availability and its hold, and the
 * one definition of which lines can be ordered. The cart page, the checkout page and PlaceOrder all decide with it.
 */
class CartLines
{
    /** @var Collection<int, CartItem>|null */
    private ?Collection $lines = null;

    /** @var Collection<string, int>|null */
    private ?Collection $purchasableIds = null;

    public function __construct(private readonly ?Cart $cart) {}

    /**
     * Whether a line can become part of an order now: its purchasable exists and passes the purchasability rule, its
     * hold is live and covers the whole line, and it has the stock the line asks for. A variant's `qty` is on-hand
     * stock, so staff lowering it below a hold makes the line short even though the hold is live.
     */
    public static function canOrder(CartItem $line, (Model&Purchasable)|null $purchasable, ?StockReservation $reservation, bool $isPurchasable): bool
    {
        return $purchasable !== null
            && $isPurchasable
            && $line->purchasable_type === $purchasable->getMorphClass()
            && $reservation !== null
            && $reservation->isLive()
            && $reservation->qty === $line->qty
            && $purchasable->onHandQty() >= $line->qty
            && $line->qty <= $purchasable->maxPerLine();
    }

    /**
     * Every line of the cart, oldest first, each with its printing and the purchasable's availability loaded, and its
     * hold (or null) set as the `reservation` relation from one read of the cart's reservations.
     *
     * @return Collection<int, CartItem>
     */
    public function lines(): Collection
    {
        if ($this->lines !== null) {
            return $this->lines;
        }

        if ($this->cart === null) {
            return $this->lines = new Collection;
        }

        $reservations = $this->cart->reservations()->get()->keyBy(fn (StockReservation $reservation): string => $reservation->purchasable_type.':'.$reservation->purchasable_id);

        return $this->lines = $this->cart->items()
            ->with(['purchasable' => fn (MorphTo $morph) => $morph
                ->constrain([Variant::class => fn (Builder $variants) => $variants->withReservedQty()])
                ->morphWith([
                    Variant::class => ['printing.card', 'printing.cardWithTrashed', 'printing.set'],
                    GradedItem::class => ['printing.card', 'printing.cardWithTrashed', 'printing.set'],
                ]),
            ])
            ->orderBy('id')
            ->get()
            ->each(fn (CartItem $line) => $line->setRelation('reservation', $reservations->get($line->purchasable_type.':'.$line->purchasable_id)));
    }

    /**
     * The cart's purchasables that still pass the purchasability rule, as `type:id` keys, one query per type.
     *
     * @return Collection<string, int>
     */
    public function purchasableIds(): Collection
    {
        if ($this->purchasableIds !== null) {
            return $this->purchasableIds;
        }

        $passing = new Collection;

        foreach ($this->lines()->filter(fn (CartItem $line): bool => $line->purchasable instanceof Purchasable)->groupBy('purchasable_type') as $type => $lines) {
            $class = PurchasableTypes::classFor($type);

            if ($class === null) {
                continue;
            }

            foreach ($class::purchasableIdsAmong($lines->pluck('purchasable_id')->map(fn ($id): int => (int) $id)) as $id => $_) {
                $passing->put($type.':'.$id, $id);
            }
        }

        return $this->purchasableIds = $passing;
    }

    /**
     * A line that fails the purchasability rule: no price, card or printing trashed, game inactive, row deleted, a
     * graded item withdrawn or sold.
     */
    public function isGone(CartItem $line): bool
    {
        return ! $line->purchasable instanceof Purchasable || ! $this->purchasableIds()->has($line->purchasable_type.':'.$line->purchasable_id);
    }

    /**
     * Whether the line still holds stock and can still be bought, short or not.
     */
    public function isHeld(CartItem $line): bool
    {
        return ! $this->isGone($line) && (bool) $line->reservation?->isLive();
    }

    /**
     * A held line whose purchasable has less stock than the line asks for.
     */
    public function isStockShort(CartItem $line): bool
    {
        return $this->isHeld($line) && $line->purchasable->onHandQty() < $line->qty;
    }

    public function isOrderable(CartItem $line): bool
    {
        return self::canOrder(
            $line,
            $line->purchasable instanceof Purchasable ? $line->purchasable : null,
            $line->reservation,
            ! $this->isGone($line),
        );
    }

    /**
     * The lines that hold stock and can still be bought: the ones the countdown and the subtotal are about.
     *
     * @return Collection<int, CartItem>
     */
    public function heldLines(): Collection
    {
        return $this->lines()->filter(fn (CartItem $line): bool => $this->isHeld($line))->values();
    }

    /**
     * Whether an order can be placed from the cart as it stands: something is in it and every line can be ordered.
     */
    public function canPlaceOrder(): bool
    {
        $lines = $this->lines();

        return $lines->isNotEmpty() && $lines->every(fn (CartItem $line): bool => $this->isOrderable($line));
    }

    /**
     * How many held items travel insured (a graded card each), which checkout charges per item.
     */
    public function insuredCount(): int
    {
        return $this->heldLines()->sum(fn (CartItem $line): int => $line->purchasable->isInsured() ? $line->qty : 0);
    }

    /**
     * Whether any held line is not already in a hard case, so the optional protective packing means something. A cart
     * of graded cards only has nothing to pack.
     */
    public function hasPackableLines(): bool
    {
        return $this->heldLines()->contains(fn (CartItem $line): bool => ! $line->purchasable->isInsured());
    }

    /**
     * The soonest `expires_at` of a held line, or null when nothing is held.
     */
    public function expiresAt(): ?CarbonInterface
    {
        return $this->heldLines()->map(fn (CartItem $line): CarbonInterface => $line->reservation->expires_at)->min();
    }

    /**
     * Satang: unit price times quantity over the held lines.
     */
    public function subtotal(): int
    {
        return $this->heldLines()->sum(fn (CartItem $line): int => $line->unit_price_satang * $line->qty);
    }
}
