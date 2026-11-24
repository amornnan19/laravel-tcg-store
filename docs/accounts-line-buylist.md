# Accounts, LINE, deck lists and the buylist

The four things that make this a card shop rather than a generic store.

## LINE Login

Thai customers have LINE. Asking them to invent a password loses sales at the one moment they were
ready to buy.

```bash
composer require laravel/socialite socialiteproviders/line
```

```
GET /auth/line           → Socialite::driver('line')->redirect()
GET /auth/line/callback  → match on line_user_id
                           → found: log in
                           → not found but the email matches a user: link the accounts
                           → neither: create the user from the LINE profile
```

- `users.line_user_id` is unique and is the identity. The display name and picture are cached, not
  trusted.
- Email may be absent from a LINE profile. The user record must work without one, which means
  email cannot be the login key and cannot be `not null`.
- Email-and-password sign-in still exists, for staff and for anyone who wants it. LINE is the
  default, not the only way.
- Linking happens from the account page too, so an existing customer can attach LINE later.

## Notifications

One channel preference per customer, used everywhere: LINE when they have linked it and opted in,
email otherwise.

Write one `ShopNotification` base that routes on `users.line_notify_opt_in`, and send every message
through it: payment received, order shipped, price alert, restock alert, pre-order balance due,
buylist re-assessment. One place to change when the LINE Messaging API changes.

LINE messages are short and carry a link back. They are not the place for an order summary.

## Wishlist and alerts

Three tables, three different things ([data-model.md](data-model.md)):

| | What it watches | Fires when |
|---|---|---|
| `wishlist_items` | a printing | never — it is a list |
| `price_alerts` | a variant, with a target | the variant's price drops to or below the target |
| `restock_alerts` | a variant | the variant's available quantity goes from zero to more than zero |

Both alert types fire from model events on `Variant`, queued, and both deactivate themselves after
firing so a customer is told once rather than every time stock wobbles. A price alert on an
out-of-stock variant does not fire; it waits.

The deck-list importer sets restock alerts in bulk — "ตั้งแจ้งเตือนทั้ง 2 ใบ" — so the action takes
an array of variant ids, not one.

## Store credit

An append-only ledger, `store_credit_entries`, with `users.store_credit_satang` as a cached sum.
Every write goes through a `RecordStoreCredit` action that writes the entry and updates the cache in
one transaction, and nothing else touches the column.

Credit is spent at checkout as `orders.credit_used_satang`, capped at the order total. It never
expires, and the account page shows the ledger, because a customer who cannot see where their
credit came from does not trust it.

## The deck-list importer

A player arrives with 48 lines of text from a deck builder. The importer turns that into a cart in
three clicks, and that is the single strongest reason for a competitive player to use this shop
rather than a marketplace.

### Parsing

`App\Actions\ParseDeckList` takes the raw text and the game, and returns a result per line.

Formats to accept, because this is what deck builders export:

```
4x OP05-119 Monkey D. Luffy
4 OP05-119
4x Monkey D. Luffy
4 Monkey D. Luffy
OP05-119 x4
```

Matching, in order, stopping at the first hit:

1. a set code and number — the exact printing
2. a bare card number, when the deck declares a set
3. an exact card name in any of the three languages
4. a fuzzy name match through Scout, accepted only above a confidence threshold

A line that matches nothing comes back as unmatched with its original text, so the customer can see
what the shop could not read. Never silently drop a line.

### The three buckets

Each matched line lands in one, and the summary above the list is the first thing on the screen,
because the player's first question is whether the shop has the deck, not what one card costs.

| Bucket | Meaning | What the row offers |
|---|---|---|
| **มีครบ** | available quantity ≥ requested | the cheapest available variant, with a dropdown to pick another |
| **มีไม่พอ** | 0 < available < requested | the available quantity, and the option to take what there is |
| **ไม่มี** | available is 0, or no match | a restock alert, in bulk with the others |

Default to the cheapest available variant per line, not to NM. A player buying 48 cards wants a
playable deck at a price, and the design's dropdown lets a collector override it per line.

### Adding

One action, one transaction, one reservation per line. A partial failure must not leave half a deck
in the cart with no hold on it — reserve everything or report what could not be reserved and change
nothing.

Saving the list keeps `raw_text` exactly as pasted, so the same list can be re-checked next week
against new stock.

## The buylist

Where the shop's stock comes from. The whole flow turns on one thing: the customer must know what
they will be paid **before** they pack a parcel.

### Prices

`buylist_prices` holds cash and credit per printing per condition. Credit is higher — the design
shows 15% — because credit comes back as a sale. A printing with no row is not bought, and the
search simply does not find it; the page says so in one line rather than showing a zero.

`weekly_quota` and `accepted_this_week` are what produce "ร้านรับซื้อครบโควตาสัปดาห์นี้" instead of a
silent refusal.

### Submitting

```
search or paste a list → pick condition and quantity per line → see cash and credit totals
  → submit → submission number, a quote valid for 7 days, packing instructions and the address
```

`quote_expires_at` is 7 days out and is shown on the page and in the confirmation. A quote that
expires while the parcel is in transit is the fastest way to lose a seller for good — if cards
arrive after it, honour it and note why.

### Re-inspection

The honest part, and the part most shops hide.

```
status: submitted → received → (revised) → accepted → paid
```

When the shop grades a card lower than the customer claimed, the submission goes to `revised`, the
line keeps both `claimed_condition` and `assessed_condition` with a photograph, and the customer is
asked to confirm the new total or have the cards returned free. Nothing is paid and nothing is
absorbed into stock while a submission is `revised`.

Say all of this on the storefront before a customer submits, not after. The design does:
"สภาพที่คุณเลือกเป็นการประเมินเบื้องต้น ร้านจะตรวจซ้ำเมื่อการ์ดถึง ถ้าไม่ตรง ร้านจะส่งรูปและราคาใหม่ให้ยืนยัน
หรือส่งการ์ดคืนฟรี".

### Paying

On `accepted`:

- **credit** — a `store_credit_entries` row, available immediately
- **cash** — a transfer, recorded with its reference, within one working day

Either way the cards become stock: an `AcceptBuylistSubmission` action creates or increments the
matching variants in the same transaction that pays the customer. That is the loop that keeps the
shelves full, and it is the one place where the buylist and the catalogue meet.
