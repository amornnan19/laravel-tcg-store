# Architecture

## The stack, and why

| Piece | Version | Why this one |
|---|---|---|
| Laravel | 13 | |
| PHP | 8.5 | Backed enums carry condition, finish, language and order status |
| Livewire | 4 | The storefront is a handful of stateful screens — a variant table, a filter sheet, a deck-list parser. Those are server-rendered components with local state, which is exactly Livewire's shape |
| Filament | 5 | Asked for, and it rides on Livewire, so the admin and the storefront share one runtime, one asset build and one mental model |
| Tailwind | 4 | Already in the skeleton. The design tokens map onto it directly |
| Scout + Meilisearch | 11 | Instant search across three scripts with typo tolerance. See [search.md](search.md) |
| Socialite | 5 | LINE Login |
| Pest | 5 | Already in the skeleton |

There is no Inertia and no SPA. A card shop is read-heavy and link-shaped: every card, set and
search result is a URL someone pastes into a group chat. Server-rendered pages with Livewire for the
interactive parts keeps that true without a second stack.

## Shape of the app

```
app/
  Enums/              CardCondition, CardFinish, CardLanguage, OrderStatus, PaymentMethod, ...
  Models/             Game, Set, Card, Printing, Variant, GradedItem, SealedProduct, Order, ...
  Livewire/
    Storefront/       one component per screen section (storefront.md names them)
  Filament/
    Resources/        the admin (admin.md)
  Actions/            one class per transaction: PlaceOrder, ReserveStock, ParseDeckList, ...
  Search/             the query parser and the Meilisearch index definitions
  Support/            Money, Sku, CardNumber
  Jobs/               ReleaseExpiredReservations, RecordDailyPrices, FireRestockAlerts, ...
resources/views/
  livewire/           component views
  components/         Blade components for the design system: card tile, condition badge, price tag
```

**Actions, not fat controllers or fat models.** Anything that writes across more than one table —
placing an order, accepting a buylist submission, spending store credit — is a single invokable
class in `app/Actions` that owns its transaction. A Livewire component calls it and renders the
result. That is what keeps the same logic available to Filament, to an Artisan command and to a
test.

## Request flow

A normal page:

```
route (web.php, named)
  → controller or a full-page Livewire component
    → query scopes on Eloquent (never raw SQL in a component)
      → Blade, using the design-system components
```

A search:

```
Livewire\Storefront\SearchBox
  → App\Search\QueryParser          does this look like a set code or a card number?
    → jump to the set or printing    when it does
    → Scout query against Meilisearch when it does not
      → hydrate printings, render the autocomplete rows
```

A purchase:

```
AddToCart action → ReserveStock action (writes stock_reservations)
  → checkout page → PlaceOrder action (one transaction: order, items, payment, reservation → consumed)
    → payment confirmation, by webhook or by an admin at the payment desk
      → order status moves, the customer is told through their chosen channel
```

## Conventions

### Money

Integer satang, in a column ending `_satang`, cast through a `Money` value object in
`app/Support`. Format only in Blade, through one `<x-price>` component, so `฿ 4,290` is written in
exactly one place. No floats anywhere near a price; no `decimal` columns.

### Enums

Every closed set of values is a PHP backed enum in `app/Enums`, cast on the model:

```php
enum CardCondition: string
{
    case Nm = 'nm';
    case Lp = 'lp';
    case Mp = 'mp';
    case Hp = 'hp';
    case Dmg = 'dmg';

    public function sort(): int { ... }     // NM is better than LP, in SQL too
    public function label(): string { ... } // Thai, from lang/th
    public function color(): string { ... } // the token, so the badge is never hand-coloured
}
```

The badge colours in the design come from the enum, not from Blade. One place to change them.

### Scopes over ad-hoc queries

Three scopes get written early and used everywhere:

- `Variant::available()` — `qty` minus unexpired reservations, greater than zero
- `Printing::forLanguage($language)` — the language tabs
- `Card::withCheapestVariant()` — the "from" price on every tile, as one query, not N+1

### Translations

Thai lives in `lang/th/`. Blade never holds a Thai string literal except in a one-off marketing
block. Card names are data and are never translated.

### Images

Card art, real photographs of a variant, graded-card scans and slip uploads all go through the
filesystem disk, never into the repository. Thumbnails are generated on upload, not on request.

### Testing

Feature tests, with factories. The ones worth writing first, because they encode decisions that are
easy to break later:

- a variant's available quantity drops while a reservation is live and returns when it expires
- an order keeps its item names and prices after the catalogue row is edited
- a slip whose amount is short moves the payment to `rejected` with a reason, not to `confirmed`
- the deck-list parser puts a line with more quantity than stock in the partial bucket
- a buylist submission re-assessed at a lower condition needs the customer's confirmation before
  it can be paid

## What is deliberately not here

- **No separate API.** If a mobile app comes later, add API routes then; building them now would be
  a second surface with no consumer.
- **No queue worker beyond the default database queue.** The jobs are small: releasing
  reservations, recording prices, firing alerts, generating thumbnails.
- **No multi-tenancy, no multi-currency, no multi-warehouse.** One shop, THB, one stock location.
  All three are easy to add later and expensive to carry now.
