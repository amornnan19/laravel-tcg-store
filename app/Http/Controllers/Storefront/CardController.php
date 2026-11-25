<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\Language;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * `/c/{card:slug}`: the card page (M04/D03). The header is the printing `?p=` names, or the card's in-stock-first
 * cheapest one; the language tabs, variant rows, stepper, chart and sticky bar are the `VariantTable` component.
 */
class CardController extends Controller
{
    private const OTHER_CARDS = 12;

    public function __invoke(Request $request, Card $card): View
    {
        // A trashed card never binds, and a printing of an inactive game is not visible: either way there is nothing to show.
        $printings = $card->printings()->searchVisible()->with(['set', 'variants' => fn (HasMany $variants) => $variants->withReservedQty()])->get();

        abort_if($printings->isEmpty(), 404);

        $requested = $request->query('p');
        $printing = (is_string($requested) && ctype_digit($requested) ? $printings->firstWhere('id', (int) $requested) : null)
            ?? $this->defaultPrinting($printings);

        // The summary under the name describes the printing in the header, not every printing of the card.
        $variants = $printing->variants;
        $available = $variants->filter(fn (Variant $variant): bool => $variant->isAvailable());
        $availablePrices = $available->whereNotNull('price_satang')->pluck('price_satang');
        $anyPrices = $variants->whereNotNull('price_satang')->pluck('price_satang');

        return view('card', [
            'card' => $card,
            'game' => $card->game,
            'printing' => $printing,
            'info' => $this->info($card, $printing, $printings),
            'title' => $printing->display_name.' · '.$printing->display_code,
            'hasRealPhoto' => $variants->contains(fn (Variant $variant): bool => $variant->has_real_photo),
            'inStock' => $available->isNotEmpty(),
            'availableQty' => $available->sum(fn (Variant $variant): int => $variant->availableQty()),
            'lowSatang' => $availablePrices->min() ?? $anyPrices->min(),
            'highSatang' => $availablePrices->max() ?? $anyPrices->max(),
            'otherPrintings' => $this->otherCards($printing),
        ]);
    }

    /**
     * The card-info rows, label to value, all from data: the set, number, rarity, type, languages the shop has
     * and whatever traits the game stores for the card.
     *
     * @param  Collection<int, Printing>  $printings
     * @return array<string, string>
     */
    private function info(Card $card, Printing $printing, Collection $printings): array
    {
        $languages = collect(Language::cases())
            ->filter(fn (Language $language): bool => $printings->contains('language', $language))
            ->map(fn (Language $language): string => $language->shortCode())
            ->implode(' · ');

        $info = [
            __('storefront.card.info_set') => $printing->set->display_code.' '.$printing->set->name_en,
            __('storefront.card.info_number') => $printing->number,
            __('storefront.card.info_rarity') => strtoupper($printing->rarity_code),
        ];

        if (filled($card->card_type)) {
            $info[__('storefront.card.info_type')] = ucfirst($card->card_type);
        }

        $info[__('storefront.card.info_languages')] = $languages;

        foreach ($card->traits ?? [] as $key => $value) {
            $text = is_array($value) ? collect($value)->filter(fn (mixed $item): bool => is_scalar($item))->implode(', ') : (is_scalar($value) ? (string) $value : '');

            if ($text === '') {
                continue;
            }

            $label = Lang::has('storefront.card.trait.'.$key) ? __('storefront.card.trait.'.$key) : ucfirst(str_replace('_', ' ', (string) $key));
            $info[$label] = $text;
        }

        return $info;
    }

    /**
     * A printing with something to buy at a price first (never one whose only available variants are unpriced),
     * then the lowest price, then the oldest.
     *
     * @param  Collection<int, Printing>  $printings
     */
    private function defaultPrinting(Collection $printings): Printing
    {
        return $printings
            ->sortBy(function (Printing $printing): array {
                $stock = $printing->stockSummary();

                return [$stock['from_satang'] !== null ? 0 : 1, $stock['from_satang'] ?? $stock['from_satang_any'] ?? PHP_INT_MAX, $printing->id];
            })
            ->first();
    }

    /**
     * Other cards of the same set and language, in card-number order. The numbers are sorted here because the
     * column is a string (`062`, `215/197`); only the twelve shown are loaded with their variants.
     *
     * @return Collection<int, Printing>
     */
    private function otherCards(Printing $printing): Collection
    {
        $ids = Printing::query()
            ->searchVisible()
            ->where('set_id', $printing->set_id)
            ->where('language', $printing->language->value)
            ->where('card_id', '!=', $printing->card_id)
            ->get(['id', 'number'])
            ->sortBy([
                fn (Printing $a, Printing $b): int => (Printing::plainNumber($a->number) ?? PHP_INT_MAX) <=> (Printing::plainNumber($b->number) ?? PHP_INT_MAX),
                fn (Printing $a, Printing $b): int => strnatcmp($a->number, $b->number),
            ])
            ->take(self::OTHER_CARDS)
            ->pluck('id');

        return Printing::query()
            ->whereIn('id', $ids)
            ->withCheapestVariant()
            ->get()
            ->sortBy(fn (Printing $other): int => $ids->search($other->id))
            ->values();
    }
}
