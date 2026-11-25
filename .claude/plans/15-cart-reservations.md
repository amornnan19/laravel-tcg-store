# M4 · Cart and reservations

> **สรุป (ไทย):** แบ่งเป็น 2 ขั้น ทำทีละขั้น
> **A — การล็อกสต็อกและปุ่มเพิ่มลงตะกร้า:** สร้างตาราง `carts`, `cart_items`, `stock_reservations` แล้วเปลี่ยน "จำนวนที่ซื้อได้" ทั้งร้านให้หักของที่ล็อกค้างอยู่ออก
> เพิ่ม action `ReserveStock` / `AddToCart` และปุ่ม "เพิ่มลงตะกร้า" ในหน้าการ์ด ส่วน header (เดสก์ท็อป) กับแถบล่าง (มือถือ) จะมีลิงก์ "ตะกร้า" พร้อมตัวนับ
> **B — หน้าตะกร้า `/cart`:** แสดงรายการ ปุ่มเพิ่ม/ลดจำนวน ปุ่มลบ และนับถอยหลัง "ล็อกของในตะกร้าไว้อีก mm:ss"
> พอหมดเวลา แถวนั้นจะขึ้นป้ายบอกพร้อมปุ่ม "ล็อกอีกครั้ง" ถ้าของถูกคนอื่นล็อกไปแล้ว จะเสนอใบเดียวกันที่สภาพอื่นแทน ตามดีไซน์หน้า "สต็อกหลุด"
> มี job `ReleaseExpiredReservations` รันทุกนาที
> ยังไม่ทำ checkout หรือปุ่มชำระเงิน (เป็นงาน M5) จึงไม่มีปุ่มที่กดแล้วไม่ทำอะไร

## Context

M3 shipped browsing; the card page has a variant table, a stepper and a sticky total but no add button
("until M4"). M4 in `docs/README.md` is "the cart, `stock_reservations`, the release job". The behaviour is
specified in `docs/payments-shipping.md` § "The 15-minute stock lock", `docs/data-model.md` § "Cart and orders",
`docs/architecture.md` (AddToCart → ReserveStock, `Variant::available()`, the first test to write), and the
canvas artboards `MCheckout` (cart part only), `MCardDetail`/`DCardDetail` (add button + lock line), `DHome`
(header cart link), `MHome` (bottom nav), `States` (empty cart, "สต็อกหลุด"). Local canvas copies:
`storage/canvas-ref/project/`. The user delegated every decision for M4 to the orchestrator; they are below.

## Rules in force (read before each step)

`.ai/rules/project.md` — especially: **available stock is not `variants.qty`, use the scope**; money is integer
satang, formatted only by `<x-price>`; Thai only via `lang/th/`; never invent shop data; catalogue models are
`#[Unguarded]` (never pass unvalidated arrays); a Livewire action that changes state a `#[Computed]` derives
from must `unset()` every dependent computed; `aria-disabled` not `disabled` at a limit; no `overflow-hidden`
around focusable controls; focus rings via `focus-visible:`; `wire:model.live` values never rewritten
server-side; enums in `app/Enums`; `php artisan make:` for every file; a new table gets a factory and a seeder
in the same change (carts: factory only is fine for the seeder rule if a `CartSeeder` makes no sense — see
Decisions). `docs/design-tokens.md` for every colour/size. Boost `search-docs` (packages livewire, laravel)
before Livewire 4 / scheduler / morph APIs. Skills `livewire-development`, `tailwindcss-development`,
`laravel-best-practices`, `testing-best-practices`. No new dependency. Pint. The implementer does not commit.

## Decisions (do not reopen)

### Schema (Step A)

- `carts`: `id`, `token` (string 26, unique — a ULID), `timestamps`. No `user_id` yet (M6 adds it with LINE
  Login). The browser holds the token in the session under `cart_token`; the cart row is created lazily on the
  first add, never on a page view.
- `cart_items`: `id`, `cart_id` (fk cascade on delete), `purchasable_type` + `purchasable_id` (`morphs`),
  `qty` (unsigned integer), `unit_price_satang` (unsigned integer), `timestamps`. Unique
  `(cart_id, purchasable_type, purchasable_id)`.
- `stock_reservations`: exactly the doc's columns — `id`, `cart_id` (fk cascade), `purchasable_type` +
  `purchasable_id`, `qty` (unsigned integer), `expires_at` (timestamp), `timestamps`. Unique
  `(cart_id, purchasable_type, purchasable_id)` (one row per cart item that holds stock); index `expires_at`
  (the release job); index `(purchasable_type, purchasable_id, expires_at)` (the availability sum).
- Morph alias: `Relation::morphMap(['variant' => Variant::class])` in `AppServiceProvider::boot()`. **Not**
  `enforceMorphMap` — Filament's notifications/imports store other morph types unaliased and must keep working.
  Graded items and sealed products join the map in M8; M4 only ever writes `variant`.
- Models via `php artisan make:model -f`: `Cart` (hasMany `items`, hasMany `reservations`), `CartItem`
  (belongsTo `cart`, morphTo `purchasable`, `reservation()` returning the matching `StockReservation` of the same
  cart and purchasable), `StockReservation` (belongsTo `cart`, morphTo `purchasable`, `scopeLive()` =
  `expires_at > now()`, `isLive()`, cast `expires_at` datetime). These are **not** catalogue models: use
  `$fillable`, not `#[Unguarded]`. Factories for all three. No seeder: a seeded cart has no owner session and
  would only hold stock against nobody. (State this in the report — it is the deliberate exception to "a new
  table gets a seeder".)
- `config/shop.php` gains `'reservation_minutes' => 15`. Every place that needs 15 reads it.

### Availability (Step A) — the one definition

- `Variant::liveReservations()`: `morphMany(StockReservation::class, 'purchasable')` constrained to live rows.
- `Variant::scopeWithReservedQty()`: `withSum('liveReservations as reserved_qty', 'qty')`.
- `Variant::scopeAvailable()`: `qty` greater than the summed live reservations, as a correlated subquery built
  with the query builder (`whereRaw` with bindings is fine; portable SQL only — `coalesce(sum(qty), 0)`, the
  morph type `'variant'`, `expires_at > ?` bound to `now()`).
- `Variant::availableQty()`: `max(0, qty - reserved)`, where reserved is the `reserved_qty` attribute when the
  query loaded it, else the loaded `liveReservations` relation's sum, else one query. `isAvailable()` =
  `availableQty() > 0`. Update both docblocks (remove the "M4 subtracts…" wording).
- Every storefront query that eager-loads variants loads them `withReservedQty()` so there is no extra query
  per variant: `Printing::scopeWithCheapestVariant()`, `CardController` (`with(['set', 'variants' => …])`),
  `VariantTable::printings()`, and any other `with('variants')` / `load('variants')` on a storefront path
  (grep `app/Http/Controllers/Storefront`, `app/Livewire/Storefront`, `app/Search`). Existing query-count
  tests must keep passing unchanged.
- **The search index stays on on-hand stock.** `Printing::stockSummary(bool $onHand = false)`: with
  `$onHand = true` a variant counts when `qty > 0` and `available_qty` sums `qty`; `toSearchableArray()` calls
  `stockSummary(onHand: true)`. Reason: a reservation never triggers a re-index, so an index written while
  something was held would stay "sold out" after the hold expired. Tiles and rows (`<x-card-tile>`,
  `<x-card-row>`) keep calling `stockSummary()` (live), so a tile whose last copy is held shows "หมด" dimmed even
  if the index still lists it under "in stock only". Write this reason in the `stockSummary()` docblock.
- Admin is unchanged: Filament keeps editing on-hand `qty`.

### Actions (Step A)

All in `app/Actions`, one class per transaction (`php artisan make:class`).

- `App\Support\CurrentCart` (scoped binding): `find(): ?Cart` (from the session token; null when none or the
  row is gone) and `findOrCreate(): Cart` (creates the row, writes the token to the session).
- `ReserveStock::handle(Cart $cart, Variant $variant, int $additional): StockReservation` — in
  `DB::transaction`: re-read the variant with `lockForUpdate()` (a no-op on SQLite, which serialises writes;
  real on MySQL/Postgres); `available = qty − live reservations of every cart`; if `$additional > available`
  throw `App\Exceptions\InsufficientStock` carrying `available` (int, ≥ 0); else upsert this cart's reservation
  row for the variant: `qty` = this cart's line qty after the change (an expired row is treated as qty 0 before
  adding), `expires_at = now() + reservation_minutes`. It does not write `cart_items`.
- `AddToCart::handle(Cart $cart, Variant $variant, int $qty): CartItem` — validates `$qty >= 1` and the variant is
  purchasable (price not null, printing visible via `Printing::searchVisible()`; otherwise throw
  `App\Exceptions\NotPurchasable`), then in one transaction: `ReserveStock` for `$qty` more, then create or
  update the `cart_items` line (`qty += $qty`). `unit_price_satang` is set to the variant's current price when
  the line is created, and **re-set to the current price whenever the line's hold is renewed after it had
  expired** (see Step B); while a hold is live the snapshot never changes, so a price edit never silently alters
  a held cart (`docs/data-model.md`).
- `ChangeCartQuantity::handle(CartItem $item, int $qty): CartItem` — `$qty < 1` is rejected (removal is its own
  action). Increase: `ReserveStock` for the difference (renews `expires_at`). Decrease: lower the line and the
  reservation qty, keep `expires_at`. Applies only to a line whose hold is live; an expired line goes through
  `RenewCartItem`.
- `RemoveCartItem::handle(CartItem $item): void` — deletes the line and its reservation in one transaction.
- `InsufficientStock` / `NotPurchasable`: plain exceptions in `app/Exceptions` (make:class), never rendered as a
  500; every caller catches them.

### Card page (Step A)

- `VariantTable` gains `addToCart()`: resolves the selected variant (the existing `selected` computed, which
  already re-validates), calls `AddToCart` with `quantity()`, then `unset($this->rows, $this->selected,
  $this->cap, $this->printings)` (availability changed), resets `qty` to 1, sets `public ?string $notice` and
  `public bool $added = true`, and dispatches the browser event `cart-updated`. On `InsufficientStock`: set
  the notice to the matching sentence below and refresh the same computeds; on `NotPurchasable`: the generic
  sentence. `selectLanguage`, `selectVariant`, `increment`, `decrement` set `added = false` and clear `notice`.
- The add button: in the phone sticky bar (right of the total) and in the desktop quantity row (after the total,
  where the M3 comment says it goes). Primary style from the tokens (accent background, accent-ink text,
  `min-h-11` desktop / `min-h-13` phone as the artboard's 52 px; check the token scale). Label
  `storefront.card.add` "เพิ่มลงตะกร้า"; after a successful add, `storefront.card.added` "เพิ่มแล้ว" with an inline
  stroke check icon (`aria-hidden`), not the ✓ glyph. `wire:loading.attr="aria-busy"` and the button stays
  focusable. Not rendered when nothing is selectable (as today the bar is not rendered).
- The add result is announced: the notice sits in the existing `role="status"` paragraph region (one live
  region, not two).
- The cap sentence gains the lock line: `storefront.card.cap` becomes
  "สั่งได้มากสุด :n ใบ · ล็อกสต็อกให้ 15 นาทีเมื่อกดซื้อ" and `cap_reached` becomes
  "ครบจำนวนที่ร้านมีแล้ว — สั่งได้มากสุด :n ใบ · ล็อกสต็อกให้ 15 นาทีเมื่อกดซื้อ" — the minutes come from
  `:minutes` filled from config, not a literal 15.
- A row the current cart already holds shows, under its stock indicator, `storefront.card.in_cart`
  "ในตะกร้า :n" (`text-label text-accent`). VariantTable reads the current cart's live reservations for the
  card's variants in one query (a computed `inCart`, `variant_id => qty`). Its stock indicator shows
  availability to everyone else (`availableQty()`), so a row the customer holds entirely reads "หมด · ในตะกร้า 1"
  — which is true: no more can be added.
- `card.blade.php`'s header summary (`availableQty`, `inStock`) is live via `CardController` with
  `withReservedQty()`.

### Header and bottom nav (Step A)

- New Livewire component `Storefront\CartLink` (`php artisan make:livewire Storefront/CartLink`), props
  `variant: 'header'|'bottom'`, listens to `cart-updated` (`#[On('cart-updated')]`), count = number of lines in
  the current cart (`cart_items` rows, matching the artboard's "3 รายการ" / badge 3); no cart → 0 and no badge.
  It runs no query beyond one `count` (and none when the session has no token).
- Header (desktop only, `max-lg:hidden`): after the search box, the DHome style — `min-h-11` bordered control,
  cart stroke icon (from the artboard, `aria-hidden`), text `storefront.nav.cart` "ตะกร้า", badge with the count
  in accent. Link `route('cart')`. Accessible name includes the count: `aria-label` "ตะกร้า · :n รายการ" when
  n > 0 (`storefront.nav.cart_count`).
- Phone bottom nav: home · search (centre) · cart, so the search button stays in the middle. Same item style as
  home, badge on the icon. `aria-current="page"` on `/cart`. (Sets "หมวด" and account "บัญชี" stay out: no
  screen for the first; account is M6.)

### Cart page (Step B)

- Route `GET /cart` → `Storefront\CartController` (invokable), name `cart`, view `cart.blade.php` in the
  storefront layout, title "ตะกร้า". It renders `<livewire:storefront.cart-panel />`
  (`php artisan make:livewire Storefront/CartPanel`, matching `docs/storefront.md`).
- `CartPanel` state is only the cart from `CurrentCart` (never a cart id in a public property). Computeds:
  `lines` (items with `purchasable.printing.card`, `purchasable.printing.set`, the reservation, and the
  variant `withReservedQty()`, ordered by `id`), `expiresAt` (the soonest `expires_at` among live lines, null
  when none), `subtotal` (sum of `unit_price_satang × qty` over **live** lines). Every action unsets all three.
- Layout from `MCheckout`'s cart portion, tokens only:
  - Header row (phone): back link (the layout's existing back pattern), "ตะกร้า", "N รายการ" (`text-faint`).
    Desktop: page heading "ตะกร้า" + "N รายการ" in a `max-w-3xl` column.
  - Lock banner (only when at least one line is live): the orange "low" token tint and border, clock icon,
    "ล็อกของในตะกร้าไว้อีก mm:ss" (`storefront.cart.lock`, the time in `tabular-nums`). Countdown in Alpine from
    `expiresAt` as an ISO string plus a server `now` offset (`Date.now()` skew corrected once); at 0 it calls
    `$wire.$refresh()` once. `aria-live` off for the ticking text; a hidden polite sentence announces only when
    it runs out.
  - Line card: `<x-card-art>` thumb (the existing small size; foil sheen comes free), the card name
    (`printing.display_name`), meta "`display_code` · `<x-language-badge>` · `<x-condition-badge>` · finish
    label", the stepper (same markup/rules as the card page stepper: `aria-disabled` at 1 and at the cap, cap =
    this line's qty + `availableQty()`), "ลบ" text button (`min-h-11`), and the line price `<x-price>` of
    `unit_price_satang × qty`. Link the name to `card.show` with `?p=`.
  - Summary card "สรุปยอด": "ค่าสินค้า (N รายการ)" + subtotal; then the total row "ยอดรวม" + subtotal (no
    shipping, credit or fees: those are M5). **No checkout button and no sentence about checkout in M4** —
    no dead control, no promise.
  - Empty state (`<x-empty-state>`): heading "ยังไม่มีอะไรในตะกร้า" (from `States`). The canvas's line and
    "วางเด็คลิสต์" point at the deck importer (M7) and "ดูซิงเกิลขายดี" needs orders (M5), so neither is shown;
    the one action is "ค้นหาการ์ด" → `route('search')`. A cart whose rows were all removed shows the same.
- **Expired line** (no live reservation): the line stays, dimmed price, a status row in the low tone
  "หมดเวลาล็อกแล้ว" + button "ล็อกอีกครั้ง" (`renew(itemId)`), stepper hidden, "ลบ" kept. Expired lines are left
  out of the subtotal and the line count stays.
- `RenewCartItem::handle(CartItem $item): RenewResult` (Step B, `app/Actions`): in one transaction, take
  `min(item.qty, available)`; if > 0 reserve it, set the line qty to it, refresh `unit_price_satang` to the
  current price, and return what changed (`qtyReduced` from→to, `priceChanged` from→to). If 0, nothing is
  reserved and the result carries the **substitute**: the cheapest purchasable, available variant of the same
  printing other than this one (price not null), or null. A variant that is no longer purchasable (price null,
  card trashed, game inactive) returns "gone" with no substitute.
- The panel shows the renew result on the line, one sentence each (all in `lang/th/storefront.php` `cart.*`):
  - qty reduced: "ของเหลือ :n ใบ ปรับจำนวนให้แล้ว"
  - price changed: "ราคาเปลี่ยนจาก :from เป็น :to" (amounts through `Money::format()`, the same formatter
    `<x-price>` uses — no second formatter)
  - nothing left, substitute exists (the `States` "สต็อกหลุด" card): ":name :condition ถูกคนอื่นซื้อหรือล็อกไปก่อน",
    then "มี :condition ใบเดียวกัน :price (ถูกกว่า :diff) · ยอดรวมจะลดลงเอง" or "(แพงกว่า :diff) · ยอดรวมจะเพิ่มขึ้น"
    (same price: no bracket and no total clause), buttons "เปลี่ยนเป็น :condition" (`swap(itemId, variantId)`)
    and "เอาออกจากตะกร้า" (`remove(itemId)`).
  - nothing left, no substitute: the first sentence + "เอาออกจากตะกร้า" only.
  Renew results live in a public array keyed by item id, holding only ids, ints and enum values (no models), and
  are dropped when that line changes again.
- `swap(itemId, variantId)`: re-checks that `variantId` is the substitute `RenewCartItem` would offer now (never
  trusts the client), then in one transaction removes the old line and `AddToCart`s the substitute with the old
  line's qty capped at what is available; on `InsufficientStock` shows the generic error.
- Stepper: `increment(itemId)` → `ChangeCartQuantity` +1 (on `InsufficientStock` the line shows
  "ครบจำนวนที่ร้านมีแล้ว"); `decrement(itemId)` → −1, never below 1; `remove(itemId)` → `RemoveCartItem`. Every
  item id is resolved inside the current cart (`$cart->items()->findOrFail`), so another cart's id 404s. Each
  action dispatches `cart-updated`.
- Error state: if an action throws anything unexpected, Livewire's default is fine; no custom page.

### Release job (Step B)

- `App\Jobs\ReleaseExpiredReservations` (`php artisan make:job`), queued (`ShouldQueue`, default database
  queue, per `docs/architecture.md`), `handle()` deletes `stock_reservations` where `expires_at <= now()` in
  one query. Nothing else ever deletes an expired row except `RemoveCartItem` (removing a line) and the upsert
  in `ReserveStock`. Availability never depends on the job having run.
- `routes/console.php`: `Schedule::job(new ReleaseExpiredReservations)->everyMinute()->withoutOverlapping()`.
- Check what `php artisan dev` (the `composer run dev` script) starts; if it runs no scheduler, add
  `schedule:work` to it only if that is a one-line config change in the project's own dev command definition;
  otherwise leave it and say so in the report.

## Copy (all new keys, `lang/th/storefront.php`)

`nav.cart` "ตะกร้า", `nav.cart_count` "ตะกร้า · :n รายการ"; `card.add` "เพิ่มลงตะกร้า", `card.added` "เพิ่มแล้ว",
`card.in_cart` "ในตะกร้า :n", `card.add_failed_some` "ของเหลือให้สั่งได้อีก :n ใบ ลองลดจำนวนลง",
`card.add_failed_none` "ตัวเลือกนี้ถูกคนอื่นล็อกไปหมดแล้ว ลองตัวเลือกอื่น", `card.add_failed` "เพิ่มลงตะกร้าไม่สำเร็จ ลองอีกครั้ง",
`card.cap` / `card.cap_reached` as above with `:minutes`; `cart.title` "ตะกร้า", `cart.lines` ":n รายการ",
`cart.lock` "ล็อกของในตะกร้าไว้อีก :time", `cart.lock_ended` "หมดเวลาล็อกของในตะกร้าแล้ว",
`cart.remove` "ลบ", `cart.expired` "หมดเวลาล็อกแล้ว", `cart.renew` "ล็อกอีกครั้ง",
`cart.qty_reduced`, `cart.price_changed`, `cart.taken`, `cart.substitute_cheaper`, `cart.substitute_dearer`,
`cart.substitute_same`, `cart.swap` "เปลี่ยนเป็น :condition", `cart.remove_line` "เอาออกจากตะกร้า",
`cart.at_cap` "ครบจำนวนที่ร้านมีแล้ว", `cart.summary` "สรุปยอด", `cart.items_total` "ค่าสินค้า (:n รายการ)",
`cart.total` "ยอดรวม", `cart.empty` "ยังไม่มีอะไรในตะกร้า", `cart.search` "ค้นหาการ์ด" (sentences as written in
"Cart page" above).

## Step A — reservations, add to cart, cart link (#15)

Migrations, models, factories, morph map, config, availability scope/accessors, `stockSummary(onHand:)`,
CurrentCart, ReserveStock, AddToCart, ChangeCartQuantity, RemoveCartItem, exceptions, VariantTable add +
in-cart, CartLink in header and bottom nav. The `cart` route and invokable `CartController` are registered in
Step A so the new links never 404: Step A's `cart.blade.php` renders the empty state, or a plain read-only list
of line names, quantities and `<x-price>` line totals. Step B replaces that list with `CartPanel`.

Tests (feature, Pest, factories):
1. `docs/architecture.md`'s first test: a variant's available quantity drops while a reservation is live and
   returns when it expires (`travel(16)->minutes()`), via both `scopeAvailable()` and `availableQty()`.
2. `ReserveStock` refuses more than available and the exception carries the number left; two carts: the second
   cannot reserve the last copy the first holds; an expired hold of another cart does not block.
3. `AddToCart` twice on the same variant makes one line with summed qty and one reservation with that qty;
   `unit_price_satang` is the price at add time and does not change when the variant price is edited while the
   hold is live.
4. `AddToCart` refuses a variant with no price and one whose card is trashed.
5. `ChangeCartQuantity` up renews `expires_at`, down keeps it; `RemoveCartItem` deletes line and reservation.
6. `toSearchableArray()` counts on-hand stock while `stockSummary()` subtracts a live reservation.
7. VariantTable: `addToCart` creates the cart, the session token, the line and the reservation; dispatches
   `cart-updated`; shows "เพิ่มแล้ว"; the stepper cap afterwards is reduced; a fully-held row shows "ในตะกร้า";
   insufficient stock shows the `add_failed_some` sentence with the number.
8. CartLink shows the count after an add and no badge with an empty session; it runs zero queries without a token.
9. Existing query-count tests (`HomePageTest`, `CardPageTest`, `SetBrowserTest`) pass unchanged.

## Step B — cart page and release job (#16)

CartController/view, CartPanel, RenewCartItem, swap, the countdown, ReleaseExpiredReservations + schedule.

Tests:
1. `/cart` with no session renders the empty state and runs no cart query; with lines renders names, prices,
   subtotal, "N รายการ" and the lock banner.
2. Increment past the available quantity shows "ครบจำนวนที่ร้านมีแล้ว" and changes nothing; decrement never
   goes below 1; remove deletes line and reservation and dispatches `cart-updated`.
3. An item id of another cart is a 404 for every action.
4. After `travel(16)->minutes()` the line shows "หมดเวลาล็อกแล้ว", is out of the subtotal, and the banner is gone.
5. Renew with stock: re-reserved, price refreshed and the price-changed sentence shown when it moved; renew
   with less stock than the line: qty reduced and sentence shown; renew with none and a cheaper LP of the same
   printing available: the "ถูกคนอื่น…" card with "ถูกกว่า"; `swap` replaces the line; a tampered `swap`
   variant id changes nothing.
6. `ReleaseExpiredReservations` deletes only expired rows; the schedule lists it every minute
   (`schedule:list` output or the Schedule facade's events).

## Out of scope

Checkout, addresses, couriers, payments, `PlaceOrder` (M5); accounts, carts attached to users, merging a guest
cart on login (M6); deck-list add-all (M7); graded and sealed purchasables, graded `status = reserved` (M8);
abandoned-cart cleanup of `carts` rows; admin views of reservations.

## Order and done

A → B, one implementer run each. After each: `verifier` (the step's tests + full suite, `npm run build`, pint,
`migrate:fresh --seed`, a browser run at 1440×900 and 390×844 with screenshots into `storage/screens/m4-<step>/`:
A = card page before/after add, header and bottom-nav badges; B = cart with two lines, the countdown, an expired
line, the "สต็อกหลุด" substitute card, empty cart), `reviewer` (this touches stock and a public endpoint), and
`designer` for B. Fix findings, then commit to `main` per step (`feat(cart): …`), closing its issue under
milestone "M4 · Cart and reservations". Bugs found inside M4's scope get their own `bug` issue in the milestone
and are fixed before the step's commit. M4 is done when both issues are closed and on a seeded local app a
visitor can add a card from the card page, see the header/bottom badge, open `/cart`, change quantities, watch
the countdown, see an expired line and renew it.
