# Search

Search is the product. A player arrives with a card name already in their head, and a collector
arrives with a set code. Neither wants to browse a category tree. Everything here exists to make the
first keystroke useful.

## Why Meilisearch

Three scripts in one index (`Charizard ex`, `リザードン`, `ลิซาร์ดอน`), typo tolerance that survives
Thai transliteration, prefix matching from the first character, and facets for the ten filters the
design shows. A database `LIKE` cannot do the first three, and a database full-text index cannot do
the second.

```bash
# development
docker run -p 7700:7700 -v ./.meili:/meili_data getmeili/meilisearch
```

```dotenv
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=
SCOUT_QUEUE=true
```

`SCOUT_QUEUE=true` matters: a stock edit in Filament must not wait on an index write.

## What gets indexed

Not cards, and not variants. **Printings.** A printing is what a search result links to — a named
card, in one set, in one language — and it is the level at which "in stock" and "from ฿ x" are
meaningful.

One index, `printings`:

```php
public function toSearchableArray(): array
{
    return [
        'id'            => $this->id,
        'card_id'       => $this->card_id,
        'name_en'       => $this->card->name_en,
        'name_ja'       => $this->card->name_ja,
        'name_th'       => $this->card->name_th,
        'set_code'      => $this->set->code,          // SV4a
        'set_name'      => $this->set->name_en,
        'number'        => $this->number,             // 190/190
        'number_plain'  => $this->numberPlain(),      // 190 — so "190" finds it
        'game'          => $this->set->game->slug,
        'rarity'        => $this->rarity_code,
        'language'      => $this->language->value,
        'card_type'     => $this->card->card_type,
        'attributes'    => $this->card->attributes,   // colour, cost: filterable per game
        'has_foil'      => $this->has_foil,
        'in_stock'      => $this->variants()->available()->exists(),
        'has_real_photo'=> $this->variants()->where('has_real_photo', true)->exists(),
        'conditions'    => $this->variants()->available()->pluck('condition'),
        'from_satang'   => $this->variants()->available()->min('price_satang'),
        'image_path'    => $this->image_path,
    ];
}
```

Index settings:

```php
'searchableAttributes' => ['name_en', 'name_ja', 'name_th', 'set_code', 'number', 'number_plain', 'set_name'],
'filterableAttributes' => ['game', 'set_code', 'rarity', 'language', 'card_type', 'attributes', 'has_foil', 'in_stock', 'conditions', 'has_real_photo', 'from_satang'],
'sortableAttributes'   => ['from_satang', 'number_plain', 'released_on'],
'rankingRules'         => ['words', 'typo', 'proximity', 'attribute', 'exactness', 'in_stock:desc', 'from_satang:asc'],
```

`in_stock:desc` in the ranking rules is the whole difference between a shop and a database: what you
can buy comes first, but what is sold out still appears, because a collector uses the catalogue to
check whether the shop ever carries a card.

### Keeping it fresh

`in_stock`, `from_satang` and `conditions` are derived from variants, so a printing must be
reindexed when its variants change. Observe `Variant` saved and deleted events and call
`$variant->printing->searchable()`. With `SCOUT_QUEUE=true` that costs the admin nothing.

A second index, `sets`, holds `code`, `name_en`, `name_th`, `game`, `card_count`. It is small, and
it powers the set jump below.

## The query parser

`App\Search\QueryParser` runs before any Scout call and answers one question: *is this a search, or
is it a destination?*

| Input | Recognised as | What happens |
|---|---|---|
| `OP05-119`, `OP05 119` | a card number with a set code | go straight to that printing |
| `SV4a 190/190` | a set code and a number | go straight to that printing |
| `SV4a`, `OP-05` | a set code alone | the "ไปที่เซ็ต" row at the top of the autocomplete, linking to the set browser |
| `190/190` | a bare number | filter the current set when one is active, else search it as text |
| anything else | text | a Scout query |

```php
final class QueryParser
{
    private const SET_CODE  = '/\b([A-Z]{1,3}\d{1,2}[a-z]?)\b/i';   // SV4a, OP05, BT16
    private const NUMBERED  = '/\b([A-Z]{2,3}\d{2})-(\d{1,3})\b/i'; // OP05-119
    private const FRACTION  = '/\b(\d{1,3})\s*\/\s*(\d{1,3})\b/';   // 190/190

    public function parse(string $raw): ParsedQuery { ... }
}
```

`ParsedQuery` is a small object with `kind` (`printing`, `set`, `text`), the matched ids and the
leftover text. The autocomplete component renders a jump row when `kind` is not `text`, and that row
is the first thing in the list — the design's "คุณพิมพ์รหัสเซ็ต — ข้ามไปไล่ดูทั้งเซ็ตตามเลขการ์ดได้เลย".

Write the parser's tests first. Set codes are irregular across five games and this is the one place
where a regression is invisible until a customer complains.

## The autocomplete

`Livewire\Storefront\SearchBox`, debounced at 150 ms, `wire:model.live.debounce.150ms`.

Results come back grouped, in this order, because that is the order of usefulness:

1. the jump row, when the parser found one
2. **การ์ด** — up to 6 printings, each with its thumbnail, set code, rarity, languages, "from" price
   and stock
3. **เซ็ต** — up to 2 sets
4. **สินค้ากล่อง** — up to 2 sealed products

Empty query shows recent searches from the session and the shop's own hot list, never a spinner.

Keyboard: arrow keys move, Enter opens, Escape closes. The box is reachable with `/` from anywhere.

## Filters

The filter sheet and the desktop sidebar are the same Livewire component rendered in two shells.
Every filter is a Meilisearch facet, so the counts beside each option are real and update as other
filters change.

| Filter | Facet | Note |
|---|---|---|
| game | `game` | drives which of the next two appear, from `games.filters` |
| set | `set_code` | searchable list; 42 sets does not fit as chips |
| rarity | `rarity` | |
| card type | `card_type` | only when the game declares it |
| colour / attribute | `attributes.*` | only when the game declares it |
| language | `language` | |
| condition | `conditions` | an array facet: a printing matches if any available variant does |
| price | `from_satang` | a range |
| in stock only | `in_stock` | default **on** |
| foil only | `has_foil` | |
| real photo | `has_real_photo` | |

The applied filters live in the URL as query parameters through Livewire's `#[Url]` attribute, so a
filtered result set is a link someone can send.

The sheet's button reads "ดูผล 124 รายการ" — run the facet count on every change so that number is
true before the sheet closes. That is what stops a customer narrowing to nothing and concluding the
shop is empty.

## Fallbacks

If Meilisearch is unreachable, the search box must not break the page. Catch the exception, log it,
and fall back to an `ILIKE` query on `name_en` and `set_code` with a one-line notice that search is
limited. A card shop with no search is closed.
