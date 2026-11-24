# TCG store — documentation

A Thai trading-card shop built as one Laravel app: storefront and admin in the same codebase.
It sells single cards, sealed product, graded cards and accessories across five games, in three card
languages, paid for with PromptPay, a bank transfer with a slip, or a card.

These documents are written from a finished design — screens, flows and tokens already exist — so
they describe behaviour that the schema alone does not imply. Read the relevant one before building
a feature.

## Read in this order

| File | What it settles |
|---|---|
| [design-tokens.md](design-tokens.md) | The colours, type, spacing and motion, transcribed from the design canvas into a Tailwind 4 `@theme` block |
| [architecture.md](architecture.md) | The stack, why each piece is there, how a request flows, and the conventions every file follows |
| [data-model.md](data-model.md) | Every table. The card → printing → variant chain is the spine of the whole app |
| [search.md](search.md) | Meilisearch index design and the query parser that turns `OP05-119` into a jump |
| [storefront.md](storefront.md) | Routes and Livewire components, one per screen in the design |
| [admin.md](admin.md) | The Filament panel: resources, the bulk stock problem, the buylist desk |
| [payments-shipping.md](payments-shipping.md) | PromptPay QR, slip checking, the 15-minute stock lock, couriers, protective packing |
| [accounts-line-buylist.md](accounts-line-buylist.md) | LINE Login, alerts, store credit, the deck-list importer and the buylist |

## The decisions already taken

Do not re-open these without a reason; they are load-bearing across the documents.

- **Laravel 13 on PHP 8.5**, one app, no separate API.
- **Blade + Livewire 4** for the storefront and **Filament 5** at `/admin`. They share a runtime, so
  there is one component model and one build.
- **Scout + Meilisearch** for search. Search is the product here, not a feature.
- **SQLite while building.** Every migration stays portable; `data-model.md` says how.
- **Money is an integer of satang.** Columns end `_satang`.
- **A variant is the sellable unit** for a single card: one printing, one condition, one finish.
- **Stock is reserved for 15 minutes** when a customer adds an item, in a real table, not in session.
- **Nothing on a customer-facing page is invented.** Prices, hours, addresses and phone numbers come
  from the shop.

## What is installed, and what is not

The skeleton is a plain Laravel 13 install: Blade, Tailwind 4 and Vite 8, with Pest, Pint and Boost
in dev. Nothing in the list below is there yet, so step 0 of the build order is installing it.

| Package | State | For |
|---|---|---|
| `livewire/livewire` 4 | **to add** | every interactive part of the storefront |
| `filament/filament` 5 | **to add** | the admin panel at `/admin` |
| `laravel/scout` 11 | **to add** | search |
| `meilisearch/meilisearch-php` | **to add** | the Scout driver |
| `laravel/socialite` 5 | **to add** | LINE Login |
| `socialiteproviders/line` | **to add** | the LINE driver for Socialite |
| `laravel/framework` 13, Tailwind 4, Vite 8, Pest 5, Pint, Boost | installed | |

Filament 5 depends on Livewire, so installing Filament brings Livewire with it. Install Livewire
first anyway, so the storefront's dependency on it is explicit in `composer.json` rather than
inherited from the admin panel — the storefront should not break the day someone removes Filament.

## Build order

Each step is shippable and testable on its own.

0. **Install the stack.** One step, before any feature:

   ```bash
   composer require livewire/livewire
   composer require filament/filament
   php artisan filament:install --panels --no-interaction
   composer require laravel/scout meilisearch/meilisearch-php
   php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider" --no-interaction
   composer require laravel/socialite socialiteproviders/line
   ```

   Check each one's installed version with `composer show <package>` before using its API, and use
   Boost's `search-docs` for anything version-specific. Then set `SCOUT_DRIVER`, the Meilisearch
   host and the LINE credentials in `.env`, and confirm `/admin` renders before moving on.

1. **Catalogue** — games, sets, cards, printings, variants. Filament resources for all five, a
   seeder with one real set per game, factories for Pest.
2. **Search** — Scout, Meilisearch, the index, the query parser, the autocomplete component.
3. **Browsing** — search results, filters, the card page with the variant table, the set browser.
4. **Cart and reservations** — the cart, `stock_reservations`, the release job.
5. **Checkout and payment** — addresses, couriers, protective packing, PromptPay QR, slip upload,
   the admin's payment desk.
6. **Accounts** — LINE Login, order history, tracking, the wishlist, alerts.
7. **Deck-list importer** — the parser, the three buckets, add-all.
8. **Graded cards and sealed product** — the two catalogue types that do not fit the variant chain,
   plus pre-orders with deposits.
9. **Buylist** — buy prices, submissions, re-inspection, store credit.

Steps 1 to 5 are the shop. Everything after is what makes it a card shop rather than a generic
store, and 7 and 9 are the two things customers cannot get elsewhere easily.

## Running it

```bash
composer install && npm install
php artisan migrate --seed
composer run dev
```

Meilisearch has to be running before step 2 of the build order; `search.md` has the command.
