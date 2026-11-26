# Data model

The schema this store stands on. Everything else — search, the variant table, the buylist, the
deck-list importer — reads from these tables, so get this right before writing a Livewire component.

## The one idea that shapes everything

A customer does not buy "a card". They buy **one condition, of one printing, in one language, in one
finish**. That is four axes, and flattening them into a single `products` row is the mistake that
makes a card shop unmaintainable.

So the chain is:

```
card        the abstract card            "Charizard ex"
  └ printing   one set, one number, one language   SV3 215/197, Japanese
      └ variant    one condition, one finish       NM, foil   ← this is what has a price and a stock count
```

`variant` is the only sellable unit for singles. The variant table in the storefront design is
literally a list of `variants` for the printings of one `card`.

Graded cards and sealed products do not fit that chain and get their own tables (below).

## Conventions

- **Money is an integer of satang** (THB minor units) in a column ending `_satang`. Never a float,
  never a decimal column. `4290.00 THB` is `429000`. Format only at the edge, in Blade.
- **Language** is a 2-letter code from a fixed enum: `ja`, `en`, `th`. It lives on `printings`,
  not on `cards` or `variants`, because a Japanese print and an English print are different objects
  with different set numbers.
- **Condition** is an enum `nm, lp, mp, hp, dmg`, stored as a string, ordered by a `sort` on the
  enum so "better than LP" is answerable in SQL.
- Every table that a customer can reach by URL carries a `slug`.
- Timestamps everywhere; `softDeletes` only on tables an admin can remove without orphaning orders
  (`cards`, `printings`, `sealed_products`). Order tables are never soft-deleted.

## Catalogue

### `games`

The brief says the game list must be easy to extend, so games are rows, never an enum in code.

| column | type | note |
|---|---|---|
| id | id | |
| slug | string, unique | `pokemon`, `one-piece`, `yugioh`, `magic`, `digimon` |
| name_en, name_th | string | |
| sort | smallint | the order of the chips in the header |
| is_active | bool | hides a game without deleting its cards |
| filters | json | which facets this game shows: `["rarity","card_type","color","language"]` |

`filters` is what lets One Piece show "colour" and Pokémon show "card type" from one storefront
component. Do not branch on `game.slug` in Blade.

### `sets`

| column | type | note |
|---|---|---|
| id | id | |
| game_id | fk | |
| code | string | `SV4a`, `OP-05`, `BT-16`. Indexed, unique per game |
| name_en, name_th | string | |
| released_on | date, nullable | |
| card_count | smallint | the printed set size, for the set browser's progress |
| sort | smallint | |

### `cards`

| column | type | note |
|---|---|---|
| id | id | |
| game_id | fk | |
| slug | string, unique | |
| name_en | string | the name customers search for |
| name_ja, name_th | string, nullable | |
| card_type | string, nullable | game-specific: `pokemon`, `trainer`, `leader`, `creature` |
| traits | json | colour, attribute, cost — whatever that game filters on |

`traits` is a json column on purpose (not `attributes`, which collides with Eloquent's internal `$attributes` property): five games with five different stat sets should not become
five tables or forty nullable columns.

### `printings`

| column | type | note |
|---|---|---|
| id | id | |
| card_id | fk | |
| set_id | fk | |
| number | string | `215/197`, `119`. Indexed with `set_id` |
| rarity_code | string | `c`, `u`, `r`, `rr`, `sar`, `sec` |
| language | enum | `ja`, `en`, `th` |
| has_foil | bool | whether a foil finish exists for this printing at all |
| image_path | string, nullable | the stock image |
| released_on | date, nullable | |

Unique index on `(set_id, number, language)`.

### `variants`

The sellable unit. One row per condition × finish that the shop actually stocks or prices.

| column | type | note |
|---|---|---|
| id | id | |
| printing_id | fk | |
| condition | enum | `nm, lp, mp, hp, dmg` |
| finish | enum | `normal`, `foil` |
| sku | string, unique | |
| price_satang | integer, nullable | null until priced |
| compare_at_satang | integer, nullable | the struck-through price |
| qty | integer | on hand, not counting reservations |
| note_th | string, nullable | the shop's own line: "มุมขวาล่างขาวเล็กน้อย" |
| has_real_photo | bool | drives the "ภาพจริง" badge |

Unique index on `(printing_id, condition, finish)`.

**Available quantity is not `qty`.** It is `qty` minus live reservations (see
[payments-shipping.md](payments-shipping.md)). Put that behind an accessor or a query scope and
never compute it ad hoc in a component.

### `variant_photos`

Real photographs of a specific variant, because a high-value single is sold on its own photograph.

| column | type |
|---|---|
| id, variant_id, path, sort |

### `price_points`

Feeds the 90-day chart on the card page. One row per variant per day, written by a nightly job from
the orders of that day; not a live aggregate.

| column | type |
|---|---|
| id, variant_id, on_date, avg_satang, low_satang, high_satang, sold_qty |

Unique index on `(variant_id, on_date)`.

## Graded cards

A graded card is one physical object with a certificate. It has no condition axis and no quantity —
it is sold once.

### `graded_items`

| column | type | note |
|---|---|---|
| id | id | |
| printing_id | fk | which print is in the slab |
| grader | enum | `psa`, `bgs`, `cgc` |
| grade | decimal(3,1) | `10.0`, `9.5` |
| cert_number | string, nullable | shown masked until the shop fills it |
| subgrades | json, nullable | centering, corners, edges, surface |
| price_satang | integer | |
| status | enum | `listed`, `reserved`, `sold`, `withdrawn` |
| front_path, back_path, label_path | string | the real photographs; all three are required to list |

Listing rule: a `graded_item` may not move to `listed` without the three photographs. Enforce it in
the model, not only in Filament, so a seeder cannot cheat.

## Sealed products and accessories

### `sealed_products`

| column | type | note |
|---|---|---|
| id | id | |
| game_id | fk, nullable | null for a generic accessory |
| set_id | fk, nullable | |
| kind | enum | `booster_box`, `booster_pack`, `starter_deck`, `accessory` |
| slug, name_th, name_en | string | |
| sku | string, unique | |
| price_satang | integer | |
| qty | integer | |
| image_path | string, nullable | |
| is_preorder | bool | |

## Pre-orders

### `preorders`

One row per sealed product being taken on pre-order.

| column | type | note |
|---|---|---|
| id | id | |
| sealed_product_id | fk | |
| release_on | date | drives the countdown |
| cancel_until | date | after this the deposit is not refundable; say it on the page |
| deposit_satang | integer | |
| full_satang | integer | |
| full_discount_bp | smallint | basis points off for paying in full, `200` = 2% |
| quota | integer | how many the shop can get |
| reserved_count | integer | |
| per_account_limit | smallint | |

The storefront's progress bar is `reserved_count / quota`. Keep `reserved_count` a real column
updated in the same transaction as the order; do not count rows on every page load.

## Customers

### `users`

The Laravel default plus:

| column | type | note |
|---|---|---|
| line_user_id | string, unique, nullable | from LINE Login |
| line_display_name | string, nullable | |
| line_notify_opt_in | bool | |
| preferred_language | enum, nullable | `ja`/`en`/`th`, remembered from filters |
| store_credit_satang | integer, default 0 | a cached balance; the ledger is the truth |

### `addresses`

| column | type |
|---|---|
| id, user_id, name, phone, line1, line2, subdistrict, district, province, postcode, is_default |

Thai addresses need `subdistrict` (ตำบล/แขวง) and `district` (อำเภอ/เขต) as their own columns;
couriers' APIs ask for them separately.

### `wishlist_items`, `price_alerts`, `restock_alerts`

| table | columns |
|---|---|
| wishlist_items | id, user_id, printing_id, created_at |
| price_alerts | id, user_id, variant_id, target_satang, channel (`line`/`email`), is_active, last_fired_at |
| restock_alerts | id, user_id, variant_id, channel, is_active, notified_at |

A restock alert has no target price; a price alert has no meaning when the variant is out of stock.
Two tables, not one with nullable columns.

## Cart and orders

### `carts`, `cart_items`

`cart_items.purchasable` is a morph to `variants`, `graded_items` or `sealed_products`. Store
`unit_price_satang` on the row at the moment it was added; a price change must not silently alter a
cart.

### `stock_reservations`

The 15-minute lock the design promises. One row per cart item that holds stock.

| column | type |
|---|---|
| id, cart_id, purchasable_type, purchasable_id, qty, expires_at |

A scheduled job releases expired rows every minute. Available stock subtracts the rows that have not
expired. This table is the reason "ล็อกของในตะกร้าไว้อีก 13:48" can be true.

### `orders`

| column | type | note |
|---|---|---|
| id | id | |
| number | string, unique | `TCG-24119`, human-facing |
| user_id | fk | |
| status | enum | `awaiting_payment`, `paid`, `packing`, `shipped`, `delivered`, `cancelled`, `refunded` |
| subtotal_satang, shipping_satang, protection_satang, insurance_satang, credit_used_satang, total_satang | integer | |
| address snapshot | json | the address as it was, not a fk: a customer editing their address must not rewrite history |
| courier | enum | `flash`, `kerry`, `jt`, `thaipost` |
| tracking_number | string, nullable | |
| placed_at, paid_at, packed_at, shipped_at, delivered_at | timestamp, nullable | these drive the tracking timeline |

### `order_items`

The purchasable morph again, plus a **snapshot** of what was bought: card name, set code, number,
language, condition, finish, unit price. An order must still read correctly after the catalogue row
is edited or deleted.

### `payments`

| column | type | note |
|---|---|---|
| id, order_id | | |
| method | enum | `promptpay`, `bank_transfer`, `card` |
| status | enum | `pending`, `submitted`, `confirmed`, `rejected`, `refunded` |
| amount_satang | integer | |
| qr_payload | text, nullable | the EMVCo string, so the same QR can be shown again |
| qr_expires_at | timestamp, nullable | |
| slip_path | string, nullable | the uploaded slip |
| slip_amount_satang, slip_ref, slip_paid_at | nullable | what was read off the slip |
| rejected_reason | string, nullable | shown to the customer verbatim |
| gateway_ref | string, nullable | for card payments |

`slip_amount_satang` exists so the "ยอดในสลิปไม่ตรง" error state can name the gap.

## Buylist

### `buylist_prices`

What the shop will pay. A printing the shop does not buy simply has no row — that is how
"ค้นไม่เจอแปลว่าตอนนี้ยังไม่รับใบนั้น" is true.

| column | type |
|---|---|
| id, printing_id, condition, finish, cash_satang, credit_satang, weekly_quota, accepted_this_week, is_active |

`finish` (`normal`/`foil`, default `normal`) is part of the key: a foil printing has its own buy price and its own
target variant when the cards are accepted into stock, so the unique key is `(printing_id, condition, finish)`.
`accepted_this_week` counts the quantity committed to submissions since Monday 00:00 shop time, when
`ResetBuylistQuotas` sets it back to 0.

### `buylist_submissions`, `buylist_submission_items`

| table | columns |
|---|---|
| buylist_submissions | id, number, user_id, payout (`cash`/`credit`), status (`draft`,`submitted`,`received`,`revised`,`accepted`,`paid`,`returned`), quoted_total_satang, final_total_satang, quote_expires_at |
| buylist_submission_items | id, submission_id, printing_id, claimed_condition, qty, quoted_unit_satang, assessed_condition, assessed_unit_satang, assessed_note, photo_path |

The `claimed_*` / `assessed_*` pair is the whole re-inspection flow: the customer states a
condition, the shop records what it found, and the difference is what the customer confirms or
refuses.

### `store_credit_entries`

An append-only ledger. `users.store_credit_satang` is a cache of its sum.

| column | type |
|---|---|
| id, user_id, delta_satang, reason (`buylist`,`order`,`refund`,`adjustment`), source_type, source_id, balance_after_satang, created_at |

## Deck lists

### `saved_deck_lists`

| column | type |
|---|---|
| id, user_id, game_id, name, raw_text, parsed (json), created_at |

`raw_text` is kept exactly as pasted so a re-check later can re-parse it against new stock.

## Indexes worth writing on day one

- `printings (set_id, number)` — the set browser and the code jump
- `printings (card_id, language)` — the language tabs on the card page
- `variants (printing_id, condition, finish)` unique — the variant table
- `variants (qty)` — "in stock only" is the most-used filter. A plain index: a partial index (`where qty > 0`) is not portable across SQLite, MySQL and Postgres
- `stock_reservations (expires_at)` — the release job
- `orders (user_id, placed_at desc)` — the account page
- `buylist_prices (printing_id, condition, finish)` unique

## A note on SQLite

The project runs on SQLite for now, which is fine for building and for Pest. Two things to keep in
mind so the move to Postgres or MySQL later is a config change and not a rewrite:

- Use `json` columns through Eloquent casts only. Do not write SQL that reaches inside them; SQLite,
  MySQL and Postgres all do that differently.
- Do not rely on SQLite's loose typing. Declare integer columns as integers and let the enum casts do
  the validating, so the same migration runs unchanged on a real server.
- Full-text search is not SQLite's job here anyway — that is Meilisearch's, see
  [search.md](search.md).
