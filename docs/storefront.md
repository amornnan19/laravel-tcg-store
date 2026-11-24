# Storefront

Every screen below exists in the design canvas:
<https://claude.ai/artifact/2NKx859ujDu9cA7n8UWLuE>

The names in the right-hand column are its artboard ids, so a question about spacing, colour or copy
has one answer and it is not in this file. Open the artboard; the values are in
[design-tokens.md](design-tokens.md).

## Routes

All named, all in `routes/web.php`.

| Route | Name | Screen |
|---|---|---|
| `/` | `home` | M01, D01 |
| `/search` | `search` | M03, D02 |
| `/g/{game:slug}` | `game.show` | D02 filtered |
| `/s/{set:code}` | `set.show` | D04 — the set browser |
| `/c/{card:slug}` | `card.show` | M04, D03 — the card page |
| `/graded/{gradedItem}` | `graded.show` | M05 |
| `/p/{sealedProduct:slug}` | `sealed.show` | D05 — sealed and pre-order |
| `/deck` | `deck.import` | M06 |
| `/buylist` | `buylist` | M09 |
| `/cart` | `cart` | M07 |
| `/checkout` | `checkout` | M07 |
| `/orders/{order:number}` | `order.show` | M08 — tracking |
| `/account` | `account` | M08 |
| `/auth/line`, `/auth/line/callback` | `line.redirect`, `line.callback` | |

Card and set URLs are short on purpose. They get pasted into LINE groups.

## Components

One Livewire component per interactive region; everything else is a plain Blade component. The split
matters: a card tile re-rendered by Livewire on every keystroke is slow and pointless.

### Livewire

| Component | Screen | Holds |
|---|---|---|
| `Storefront\SearchBox` | everywhere | the query, the parsed result, the autocomplete groups |
| `Storefront\ResultGrid` | M03, D02 | the Scout query, the facets, grid/list mode, pagination |
| `Storefront\FilterPanel` | M02b, D02 | the filter state; rendered as a bottom sheet on a phone and a sidebar on a desktop, one component |
| `Storefront\VariantTable` | M04, D03 | the language tab, the selected variant, the quantity, the sticky bar's total |
| `Storefront\SetBrowser` | D04 | the number range, "in stock only", "hide what I own" |
| `Storefront\DeckImport` | M06 | the pasted text, the parse result, the three buckets |
| `Storefront\BuylistBuilder` | M09 | the lines, cash-or-credit, the running total |
| `Storefront\CartPanel` | M07 | lines, quantities, the reservation countdown |
| `Storefront\CheckoutForm` | M07 | address, courier, protection, payment method |
| `Storefront\PaymentPanel` | M07 | the QR, the countdown, the slip upload, the polled status |
| `Storefront\AlertToggle` | M04, M05 | the wishlist heart and the restock bell |

### Blade components

These are the design system, and they are the reason the whole shop looks like one shop. Build them
before the first screen.

| Component | Props |
|---|---|
| `<x-card-tile>` | printing, from-price, stock, foil, rarity |
| `<x-card-art>` | printing, size, foil — owns the 63 × 88 ratio and the foil sheen |
| `<x-condition-badge>` | condition enum |
| `<x-rarity-badge>` | rarity code |
| `<x-language-badge>` | language enum |
| `<x-price>` | satang, compare-at — the only place money is formatted |
| `<x-stock-indicator>` | quantity, state |
| `<x-filter-chip>` | label, active, count |
| `<x-trust-row>` | — the authenticity, real-photo and shipping line |
| `<x-qr-block>` | payload, amount, expires-at |
| `<x-empty-state>` | icon, heading, line, action |
| `<x-skeleton>` | shape — the 63 × 88 skeleton, so nothing jumps when images land |

The foil sheen lives in `<x-card-art>` and nowhere else. It runs continuously on foil printings and
not at all on the rest; that is how a rare card is told apart at a glance in a grid, and it is a
deliberate exception to the usual rule against looping animation. Respect
`prefers-reduced-motion`.

## The card page, in detail

This is the screen the shop lives or dies on, so it is worth writing out.

```
back · name · wishlist · share
the card art, large, tappable to zoom; thumbnails for front, back, real photo
name, set code, rarity, game
from-price range · total stock
trust row
language tabs                       ← Livewire: changes the rows below
variant rows                        ← one per condition × finish, each with price, stock, add
quantity stepper for the selected row
a line about the 15-minute lock
90-day price chart
the graded listing of the same card, when one exists
other cards in the set
reviews
sticky bar: selected variant · quantity · total · add to cart
```

Rules that are easy to get wrong:

- **Out-of-stock rows stay visible**, dimmed, with "แจ้งเมื่อของเข้า" in place of the add button.
  Collectors use the page to learn whether the shop ever carries a card.
- **The sticky bar reflects the selected row**, not the cheapest. It is the only place the total
  appears.
- **Changing the language tab resets the selected variant** to the cheapest available row in that
  language, and resets the quantity to 1.
- The quantity stepper is capped at the selected variant's available quantity, and the cap is shown
  as a sentence, not only as a disabled button.

## Responsive

Two breakpoints, and they are different layouts rather than one layout scaled.

| | Phone (390) | Desktop (1440) |
|---|---|---|
| Search | the middle button of the bottom bar, opening a full-screen overlay | a wide field in the header |
| Filters | a bottom sheet with a "ดูผล N รายการ" button | a sidebar that is always open |
| Variants | stacked, tappable rows | a real table, six rows compared at once |
| Grid | 2 columns | 5 to 6 columns |
| Main action | a sticky bar at the foot | in place, beside the content |

Touch targets are at least 44 px on the phone. Card tiles keep the 63 × 88 ratio at every width.

## States

Every list and every panel ships with four states, and they are part of the component, not an
afterthought:

- **Loading** — a skeleton in the shape of the result, never a spinner over the page.
- **Empty** — says which filter is responsible and offers to remove it. "ไม่พบ X ที่ตรงเงื่อนไข ·
  มี 24 ใบถ้าเอาตัวกรอง NM ออก" with a button that does exactly that.
- **Out of stock** — dimmed, still readable, with the restock alert.
- **Error** — says plainly that nothing was charged and what is still intact, then offers one retry.

The design has all four drawn; follow them rather than inventing new copy.

## Performance

- `Card::withCheapestVariant()` on every grid, so a 24-tile page is two queries and not fifty.
- Card art is served as WebP at the exact rendered width, with `width` and `height` set, lazy below
  the fold and eager for the first row.
- The price chart is a static SVG rendered server-side from `price_points`; no charting library and
  no client-side fetch.
- Livewire components poll only where something really changes: the payment panel while a QR is
  live, and nothing else.
