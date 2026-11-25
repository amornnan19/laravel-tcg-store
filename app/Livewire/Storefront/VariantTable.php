<?php

namespace App\Livewire\Storefront;

use App\Actions\AddToCart;
use App\Actions\RemovePriceAlert;
use App\Actions\RemoveRestockAlert;
use App\Actions\SetPriceAlert;
use App\Actions\SetRestockAlerts;
use App\Enums\Language;
use App\Exceptions\AlertRefused;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\Card;
use App\Models\Cart;
use App\Models\PriceAlert;
use App\Models\PricePoint;
use App\Models\Printing;
use App\Models\RestockAlert;
use App\Models\StockReservation;
use App\Models\Variant;
use App\Support\CurrentCart;
use App\Support\Money;
use App\Support\PriceChart;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The interactive half of the card page: language tabs, the variant rows of the chosen language, the quantity
 * stepper, the price chart of the selected row, the add-to-cart button and the sticky bar with the total.
 *
 * Only the language, the selected variant, the quantity and the last add's notice are state, and all three are re-checked against the
 * rows on every request, so a stale or tampered value falls back to something that can be bought.
 */
class VariantTable extends Component
{
    private const CHART_DAYS = 90;

    #[Locked]
    public int $cardId;

    /** The printing the page header shows; its rows are listed first. */
    #[Locked]
    public ?int $headerPrintingId = null;

    public string $language = '';

    public ?int $variantId = null;

    public int $qty = 1;

    /** What the last add said, shown in the one live region; cleared by any change of selection. */
    public ?string $notice = null;

    /** Whether the button reads "added": the last add succeeded and the row it added is still the selected one. */
    public bool $added = false;

    /** Whether the notice reports a successful add (and offers the link to the cart) rather than an error. */
    public bool $noticeOk = false;

    /** What the price watch's baht field holds. Bound with a plain `wire:model`, so it is only sent on submit. */
    public string $priceTarget = '';

    /** Whether the price watch's form is open for the selected row. */
    public bool $watching = false;

    /** What the price watch's form refused, shown inside that form. */
    public ?string $priceWatchError = null;

    /**
     * What the last restock action said about the row it acted on, `variant id => message`, shown in that row's footer.
     *
     * @var array<int, string>
     */
    public array $restockNotices = [];

    /**
     * @param  int  $printingId  The printing the page header shows: its language is the first tab, its own rows are preferred.
     */
    public function mount(int $cardId, int $printingId): void
    {
        $this->cardId = $cardId;
        $this->headerPrintingId = $printingId;

        $printing = $this->printings->firstWhere('id', $printingId) ?? $this->printings->first();

        $this->language = ($printing?->language ?? $this->languages->first())?->value ?? '';
        $this->variantId = ($this->cheapest($this->rows->where('printing_id', $printing?->id))
            ?? $this->cheapest($this->rows))?->id;
    }

    /**
     * Changing the tab starts over: the cheapest row that can be bought in that language, and one copy.
     */
    public function selectLanguage(string $language): void
    {
        $chosen = Language::tryFrom($language);

        if ($chosen === null || ! $this->languages->contains($chosen)) {
            return;
        }

        $this->language = $chosen->value;
        $this->clearNotice();
        $this->closePriceWatch();
        $this->forgetSelection(withRows: true);

        $this->variantId = $this->cheapest($this->rows)?->id;
        $this->qty = 1;
    }

    public function selectVariant(int $variantId): void
    {
        $variant = $this->rows->firstWhere('id', $variantId);

        if ($variant === null || ! $this->isSelectable($variant)) {
            return;
        }

        $this->variantId = $variant->id;
        $this->qty = min($this->qty, $variant->availableQty());
        $this->clearNotice();
        $this->closePriceWatch();
        $this->forgetSelection();
    }

    public function increment(): void
    {
        $this->clearNotice();
        $this->qty = min($this->quantity() + 1, $this->cap);
    }

    public function decrement(): void
    {
        $this->clearNotice();
        $this->qty = max($this->quantity() - 1, 1);
    }

    /**
     * Put the chosen quantity of the selected row in the visitor's cart, which locks that stock for them. The cart
     * row is created here, on the first add. Availability changed either way, so the stock-derived computeds are
     * dropped and the next render reads the new figures.
     */
    public function addToCart(AddToCart $addToCart, CurrentCart $currentCart): void
    {
        // Exactly the row the customer chose: `selected` would fall back to another row that cannot be what they tapped.
        $variant = $this->rows->firstWhere('id', $this->variantId);

        $this->clearNotice();

        if ($variant === null || ! $this->isSelectable($variant)) {
            $this->notice = __('storefront.card.add_failed_none');
            $this->refreshStock();
            $this->variantId = $this->cheapest($this->rows)?->id;

            return;
        }

        try {
            $addToCart->handle($currentCart->findOrCreate(), $variant, $this->quantity());

            $this->notice = __('storefront.card.added');
            $this->noticeOk = true;
            $this->qty = 1;
            $this->dispatch('cart-updated');
            $this->refreshStock();

            // The last copy just went into this cart: move to the next row explicitly, and the button reads "add" again.
            $added = $this->rows->firstWhere('id', $variant->id);

            if ($added !== null && $this->isSelectable($added)) {
                $this->added = true;
            } else {
                $this->variantId = $this->cheapest($this->rows)?->id;
            }
        } catch (InsufficientStock $stock) {
            $this->notice = $stock->available > 0
                ? __('storefront.card.add_failed_some', ['n' => $stock->available])
                : __('storefront.card.add_failed_none');
        } catch (NotPurchasable) {
            $this->notice = __('storefront.card.add_failed');
        }

        $this->refreshStock();
    }

    /**
     * Asks to be told when a sold-out row can be bought again. A guest is sent to log in and returns to this card.
     */
    public function notifyRestock(int $variantId, SetRestockAlerts $setRestockAlerts): void
    {
        $this->restockNotices = [];
        $this->closePriceWatch();

        if ($this->sendGuestToLogin()) {
            return;
        }

        if ($this->rows->firstWhere('id', $variantId) === null) {
            return;
        }

        try {
            if ($setRestockAlerts->handle(auth()->user(), [$variantId]) === 0) {
                $this->restockNotices[$variantId] = (new AlertRefused('unavailable'))->message();
            }
        } catch (AlertRefused $refused) {
            $this->restockNotices[$variantId] = $refused->message();
        }

        unset($this->alerts);
    }

    public function cancelRestock(int $variantId, RemoveRestockAlert $removeRestockAlert): void
    {
        $this->restockNotices = [];

        $alert = RestockAlert::query()->where('user_id', auth()->id())->where('variant_id', $variantId)->first();

        if ($alert !== null) {
            $removeRestockAlert->handle(auth()->user(), $alert);
        }

        unset($this->alerts);
    }

    public function openPriceWatch(): void
    {
        $this->priceWatchError = null;
        $this->restockNotices = [];

        if ($this->sendGuestToLogin()) {
            return;
        }

        // The form names the row `selected` falls back to; make that the row the submit acts on.
        $this->variantId = $this->selected?->id;
        $this->priceTarget = '';
        $this->watching = true;
    }

    public function closePriceWatch(): void
    {
        $this->watching = false;
        $this->priceWatchError = null;
    }

    /**
     * Watches the selected row's price. The baht field is read into a local variable, never written back.
     */
    public function setPriceWatch(SetPriceAlert $setPriceAlert): void
    {
        $this->priceWatchError = null;

        if ($this->sendGuestToLogin()) {
            return;
        }

        // Exactly the row the customer chose, as addToCart does: `selected` may have fallen back to another one.
        $variant = $this->rows->firstWhere('id', $this->variantId);

        if ($variant === null || ! $this->isSelectable($variant)) {
            $this->priceWatchError = (new AlertRefused('unavailable'))->message();
            $this->refreshStock();
            $this->variantId = $this->cheapest($this->rows)?->id;

            return;
        }

        try {
            $baht = str_replace([',', ' ', '฿'], '', trim($this->priceTarget));

            try {
                $target = Money::fromBaht($baht)->satang();
            } catch (InvalidArgumentException) {
                throw new AlertRefused('invalid_target');
            }

            $setPriceAlert->handle(auth()->user(), $variant, $target);

            $this->watching = false;
            $this->priceTarget = '';
        } catch (AlertRefused $refused) {
            $this->priceWatchError = $refused->message();

            // The row sold out between the click and the check: show the one that can be bought now.
            if ($refused->reason === 'unavailable') {
                $this->refreshStock();
                $this->variantId = $this->cheapest($this->rows)?->id;
            }
        }

        unset($this->alerts);
    }

    public function cancelPriceWatch(int $variantId, RemovePriceAlert $removePriceAlert): void
    {
        $this->priceWatchError = null;

        $alert = PriceAlert::query()->where('user_id', auth()->id())->where('variant_id', $variantId)->first();

        if ($alert !== null) {
            $removePriceAlert->handle(auth()->user(), $alert);
        }

        unset($this->alerts);
    }

    /**
     * For a guest: remember this card and go to the login page. Returns whether it did.
     */
    private function sendGuestToLogin(): bool
    {
        if (auth()->check()) {
            return false;
        }

        $card = Card::query()->find($this->cardId);

        if ($card !== null) {
            session()->put('url.intended', route('card.show', ['card' => $card, 'p' => $this->headerPrintingId]));
        }

        $this->redirectRoute('login');

        return true;
    }

    private function refreshStock(): void
    {
        unset($this->rows, $this->selected, $this->cap, $this->printings, $this->chart, $this->inCart);
    }

    private function clearNotice(): void
    {
        $this->notice = null;
        $this->added = false;
        $this->noticeOk = false;
    }

    /**
     * One request can carry several calls, so every computed property that derives from the language or the
     * selected variant is dropped when either changes, or a later call in the bundle would read the old row.
     */
    private function forgetSelection(bool $withRows = false): void
    {
        unset($this->selected, $this->cap, $this->chart);

        if ($withRows) {
            unset($this->rows);
        }
    }

    /**
     * What the storefront can show of the card: printings of an active game, card not trashed.
     *
     * @return Collection<int, Printing>
     */
    #[Computed]
    public function printings(): Collection
    {
        return Printing::query()
            ->searchVisible()
            ->where('card_id', $this->cardId)
            ->with(['set', 'variants' => fn (HasMany $variants) => $variants->withReservedQty()])
            ->orderBy('id')
            ->get();
    }

    /**
     * The languages of those printings, in the order of the enum (JP, EN, TH).
     *
     * @return Collection<int, Language>
     */
    #[Computed]
    public function languages(): Collection
    {
        $present = $this->printings->pluck('language');

        return collect(Language::cases())->filter(fn (Language $language): bool => $present->contains($language))->values();
    }

    /**
     * The variants of the chosen language, each with its printing, grouped by printing (the header's printing first)
     * and best condition first.
     *
     * @return Collection<int, Variant>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->printings
            ->filter(fn (Printing $printing): bool => $printing->language->value === $this->language)
            ->sortBy(fn (Printing $printing): int => $printing->id === $this->headerPrintingId ? 0 : 1)
            ->flatMap(fn (Printing $printing): Collection => $printing->variants->each->setRelation('printing', $printing))
            ->values();
    }

    /**
     * How many of each variant of the card the visitor's own cart holds right now, `variant id => qty`, from one
     * query over the live reservations (none when the session has no cart). Covers every language, so it does not
     * change with the tab.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function inCart(): array
    {
        $token = app(CurrentCart::class)->token();
        $variantIds = $this->printings->flatMap(fn (Printing $printing): Collection => $printing->variants)->pluck('id');

        if ($token === null || $variantIds->isEmpty()) {
            return [];
        }

        return StockReservation::query()
            ->live()
            ->whereIn('cart_id', Cart::query()->select('id')->where('token', $token))
            ->where('purchasable_type', (new Variant)->getMorphClass())
            ->whereIn('purchasable_id', $variantIds)
            ->pluck('qty', 'purchasable_id')
            ->map(fn (mixed $qty): int => (int) $qty)
            ->all();
    }

    /**
     * The signed-in visitor's active alerts on this card's variants, from two queries per render: the variant ids with
     * a restock alert, and `variant id => target satang` for price alerts. Empty for a guest.
     *
     * @return array{restock: array<int, true>, price: array<int, int>}
     */
    #[Computed]
    public function alerts(): array
    {
        $userId = auth()->id();
        $variantIds = $this->printings->flatMap(fn (Printing $printing): Collection => $printing->variants)->pluck('id');

        if ($userId === null || $variantIds->isEmpty()) {
            return ['restock' => [], 'price' => []];
        }

        return [
            'restock' => RestockAlert::query()
                ->where('user_id', $userId)->where('is_active', true)->whereIn('variant_id', $variantIds)
                ->pluck('variant_id')->mapWithKeys(fn (mixed $id): array => [(int) $id => true])->all(),
            'price' => PriceAlert::query()
                ->where('user_id', $userId)->where('is_active', true)->whereIn('variant_id', $variantIds)
                ->pluck('target_satang', 'variant_id')->map(fn (mixed $target): int => (int) $target)->all(),
        ];
    }

    #[Computed]
    public function selected(): ?Variant
    {
        $current = $this->rows->firstWhere('id', $this->variantId);

        return $current !== null && $this->isSelectable($current) ? $current : $this->cheapest($this->rows);
    }

    /**
     * How many of the selected row can be ordered, which is the stepper's ceiling.
     */
    #[Computed]
    public function cap(): int
    {
        return $this->selected?->availableQty() ?? 0;
    }

    /**
     * The quantity to show: what was asked for, held between 1 and the cap. A method and not a computed property,
     * because the stepper changes `qty` in the same request that renders it.
     */
    public function quantity(): int
    {
        return max(1, min($this->qty, max($this->cap, 1)));
    }

    /**
     * The chart of the selected row's average price, or null when it has fewer than two days of history.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function chart(): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        return PriceChart::make(
            PricePoint::query()
                ->where('variant_id', $this->selected->id)
                ->where('on_date', '>=', today()->subDays(self::CHART_DAYS - 1)->toDateString())
                ->orderBy('on_date')
                ->get()
        );
    }

    /**
     * A row can be chosen when it can be bought now and has a price.
     */
    private function isSelectable(Variant $variant): bool
    {
        return $variant->isAvailable() && $variant->price_satang !== null;
    }

    /**
     * @param  Collection<int, Variant>  $rows
     */
    private function cheapest(Collection $rows): ?Variant
    {
        return $rows->filter(fn (Variant $variant): bool => $this->isSelectable($variant))->sortBy('price_satang')->first();
    }

    public function render(): View
    {
        return view('livewire.storefront.variant-table');
    }
}
