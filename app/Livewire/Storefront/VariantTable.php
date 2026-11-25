<?php

namespace App\Livewire\Storefront;

use App\Enums\Language;
use App\Models\PricePoint;
use App\Models\Printing;
use App\Models\Variant;
use App\Support\PriceChart;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The interactive half of the card page: language tabs, the variant rows of the chosen language, the quantity
 * stepper, the price chart of the selected row and the sticky bar with the total. There is no add button until M4.
 *
 * Only the language, the selected variant and the quantity are state, and all three are re-checked against the
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
        $this->forgetSelection();
    }

    public function increment(): void
    {
        $this->qty = min($this->quantity() + 1, $this->cap);
    }

    public function decrement(): void
    {
        $this->qty = max($this->quantity() - 1, 1);
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
            ->with(['set', 'variants'])
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
