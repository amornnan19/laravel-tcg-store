# M8 · Graded cards and sealed product

> **สรุป (ไทย):** แบ่งเป็น 3 ขั้น ทำทีละขั้น
>
> **A — ฐานของสินค้าหลายชนิด และการ์ดเกรด**
> - ตอนนี้ตะกร้า การล็อกสต็อก และคำสั่งซื้อรู้จักแค่ variant จึงต้องทำ interface `Purchasable` ให้ทุกชนิดใช้ทางเดียวกันก่อน
> - เพิ่มการ์ดเกรด (PSA / BGS / CGC) เป็นของชิ้นเดียว ตั้งขายได้ก็ต่อเมื่อมีรูปจริงครบ 3 รูป และเลขใบรับรองแสดงแบบปิดบางส่วน
> - ใส่ลงตะกร้าแล้วสถานะเป็น reserved หมดเวลาล็อกกลับเป็น listed ขายแล้วเป็น sold ยกเลิกหรือคืนเงินกลับเป็น listed
> - กล่องแข็งและประกันพัสดุบังคับสำหรับการ์ดเกรด แสดงเป็นบรรทัดในคำสั่งซื้อ
> - มีหน้า `/graded/{id}`, หน้าแก้ไขใน admin, และรายการการ์ดเกรดบนหน้าการ์ด
>
> **B — สินค้ากล่องและอุปกรณ์**
> - booster box / pack / starter deck / อุปกรณ์ มีจำนวนสต็อกและล็อก 15 นาทีแบบเดียวกับการ์ด
> - มีหน้า `/p/{slug}`, หน้าแก้ไขใน admin, กลุ่ม "สินค้ากล่อง" ในช่องค้นหา และส่วนสินค้ากล่องบนหน้าแรก
>
> **C — พรีออเดอร์**
> - สั่งจองแบบมัดจำ (จ่ายมัดจำตอนนี้ ส่วนที่เหลือก่อนวันวางจำหน่าย) หรือจ่ายเต็ม (ได้ส่วนลด)
> - มีโควต้าและแถบความคืบหน้า และจำกัดจำนวนต่อบัญชี (ต้องล็อกอิน) ทั้งสองอย่างคุมใน transaction เดียวกับคำสั่งซื้อ
> - ก่อนวางจำหน่าย 7 วัน ระบบส่งข้อความขอส่วนที่เหลือ พร้อมลิงก์กลับไปคำสั่งซื้อเดิม
> - ยกเลิกก่อน `cancel_until` คืนมัดจำ หลังจากนั้นไม่คืน และหน้าสินค้าบอกเรื่องนี้ชัดเจน
>
> **ไม่ทำ:** บัตรเครดิต, ตัวกรองการ์ดเกรดในหน้าผลค้นหา (ตาม docs เป็นตัวกรอง Meilisearch ทำทีหลัง), รับซื้อ (M9)

## Context

M8 in `docs/README.md` is "the two catalogue types that do not fit the variant chain, plus pre-orders with deposits".
The specs are:
- `docs/data-model.md` § Graded cards / Sealed products / Pre-orders;
- `docs/payments-shipping.md`: a graded hold sets `reserved` and a release sets `listed`; graded items carry a mandatory box and insurance; § Pre-orders;
- `docs/storefront.md`: routes `graded.show` and `sealed.show`, and "the graded listing of the same card" on the card page;
- `docs/search.md`: the autocomplete group "สินค้ากล่อง", up to 2;
- `docs/admin.md`: the Catalogue group has Graded items, Sealed products and Pre-orders;
- `docs/accounts-line-buylist.md`: "pre-order balance due" goes through `ShopNotification`.

Design copy:
- Flows B: "ดูรูปจริง หน้า–หลัง · ซูมได้ + เลขใบรับรองกดไปเช็กที่ PSA ได้", "ของแท้ 100% คืนได้ถ้าเคสหรือเกรดไม่ตรง", "ของชิ้นเดียว ระบบล็อกไว้ให้ 15 นาที", "ส่งแบบประกัน · กล่องแข็งกันกระแทก บังคับสำหรับของเกรด".
- DHome pre-order banner: "สั่งจองวันนี้ มัดจำ ฿ :deposit ต่อกล่อง จ่ายส่วนที่เหลือก่อนวันส่ง ยกเลิกได้ถึง :date คืนมัดจำเต็มจำนวน", "เหลือเวลาสั่งจอง :d วัน :h ชั่วโมง :m นาที", "มัดจำต่อกล่อง", "ราคาเต็ม", "จองแล้ว :n / :quota กล่อง".
- Components: the price tag "มัดจำ · เต็ม ฿ :full", the badge "พรีออเดอร์ · ส่ง :date", the graded badge "PSA 10", and the graded tile "ภาพจริงหน้า–หลัง".
- There is no M05 or D05 artboard locally, so the pages are built from existing components and tokens, as the account and deck pages were.

A read-only map of every variant-only path (cart, holds, orders, admin, search, alerts) was made before planning. Its findings are folded into Step A below. The user delegated every decision.

## Rules in force

Every rule in `.ai/rules/project.md` applies. These matter most here:
- lock first;
- the single-upsert hold;
- the purchasability check on every hold path;
- `orders snapshot what was bought`;
- money in satang via `Money`/`<x-price>`;
- never print an invented price (all new money lives in config/env or admin data);
- card art is placeholder only, and staff-uploaded photos are the only real photos;
- `receivedSatang` money model;
- guard filters re-evaluated under the order lock;
- `DB::afterCommit` for unique jobs;
- `$tries`/fallback for jobs that call external APIs;
- one gold button;
- row actions in their row;
- soft-disabled controls must answer;
- stale results;
- the Filament theme `@source` rule;
- policies on every resource;
- the trashed-parent rule.

Before writing code:
- Use Boost `search-docs` for:
  - Filament 5 FileUpload, resources, relation managers, actions and widgets;
  - Eloquent morph maps;
  - Scout multiple searchables;
  - the scheduler;
  - `lockForUpdate` on several tables.
- Load the skills `laravel-best-practices`, `livewire-development`, `tailwindcss-development` and `testing-best-practices`.

Pint applies. The implementer does not commit. Run mutation runs against the whole suite.

## Decisions (do not reopen)

### Shared foundation (Step A)

- **One contract for every purchasable.** `App\Contracts\Purchasable`, implemented by `Variant`, `GradedItem` and `SealedProduct`:
  - `static lockForHold(int $id): ?static`: `lockForUpdate` on its own table.
  - `isPurchasable(): bool`
  - `holdableQty(): int`: what can still be held now, after live reservations.
  - `unitPriceSatang(): int`
  - `maxPerLine(): int`: graded 1, others `PHP_INT_MAX`.
  - `onHeld(): void`, `onReleased(): void`: a graded item flips its status; the others do nothing.
  - `onSold(int $qty): void`, `onReturned(int $qty): void`: decrement or increment `qty`, or flip graded `sold`/`listed`.
  - `snapshot(): array`, the `order_items` snapshot columns.
  - `cartPresentation(): App\Support\LinePresentation`: a small readonly DTO with title, meta, badges, url and art for the cart, checkout and order views.
  - `isInsured(): bool`: graded true.
- **Morph aliases.** `Relation::morphMap` gains `graded` and `sealed` next to `variant`. The factories stop hard-coding `variant`.
- **Generalise the hold and order paths through the contract.** Each path is rewritten to resolve the purchasable by `purchasable_type` + `purchasable_id` via the morph map, never by id against `variants`:
  - `ReserveStock`, `AddToCart`, `ChangeCartQuantity`, `RenewCartItem`, `RemoveCartItem`, `SwapCartItem` (swap stays variant-only and refuses other types);
  - `ReleaseExpiredReservations`, `CartLines`, `CartPanel`, the cart and checkout views;
  - `PlaceOrder`, `CancelOrder` and `RefundOrder`.

  The rules for those paths:
  - **Locking.** `PlaceOrder` locks per type, in a fixed order (variants, then graded, then sealed), each set ordered by id, before any other read.
  - **Alerts.** `VariantAlerts` dispatches stay variant-only, filtered by type.
  - **Substitutes.** `RenewCartItem`'s substitute logic stays variant-only.
- **Graded release.**
  - `onReleased()` sets `listed` only when the item is still `reserved` and has no other live hold.
  - The release job, `RemoveCartItem`, and an expired line found on renew all go through it.
  - `holdableQty()` for graded is 1 when `status = listed`, and also when `status = reserved` and no live reservation exists. That second case self-heals a missed flip.
- **`order_items` snapshot becomes nullable-generic.** A migration makes `number`, `language`, `condition` and `finish` nullable, and adds `kind` (string, default `single`; values `single` / `graded` / `sealed`) and `detail` (nullable string: "PSA 10 · cert •••• 4821" or "Booster Box").
  - The order page and the admin item list render by `kind`, and never call `CardCondition::from` on a null.
- **Mandatory graded insurance.**
  - Config: `shipping.graded_insurance_satang` (env `SHIPPING_GRADED_INSURANCE_SATANG`, default 20000) per graded item.
  - `PlaceOrder` writes `insurance_satang = count(graded lines) × that`, and it is added to the total.
  - Checkout and the order page show it as a line: "กล่องแข็ง + ประกันพัสดุ (การ์ดเกรด × :n)", with the note "บังคับสำหรับการ์ดเกรด".
  - The optional protection packing stays as it is for singles.

### Graded items (Step A)

- **Enums.** `Grader` (`psa`, `bgs`, `cgc`; labels PSA, BGS, CGC; `verifyUrl(cert)` from `config('graded.verify_urls')` templates, PSA `https://www.psacard.com/cert/{cert}`, the others null so no link) and `GradedStatus` (`listed`, `reserved`, `sold`, `withdrawn`, with labels).
- **Table `graded_items`:**
  - `id`
  - `printing_id` (fk)
  - `grader`
  - `grade` (decimal 3,1)
  - `grade_label` (nullable string, e.g. "Gem Mint")
  - `cert_number` (nullable)
  - `subgrades` (json, nullable: `centering`, `corners`, `edges`, `surface`)
  - `price_satang`
  - `status` (default `withdrawn`)
  - `front_path`, `back_path`, `label_path` (nullable strings)
  - `note_th` (nullable)
  - `timestamps`
  - indexes `(printing_id, status)` and `(status, price_satang)`
- **Listing rule in the model** (`saving` hook): moving to or saving as `listed` without all three photos throws `App\Exceptions\GradedNotListable`. Filament shows it as a validation error.
- **Purchasable.** Purchasable = status listed (or self-healed reserved), price not null, and `printing` `searchVisible`.
- **Cert masking.** The docs say "shown masked until the shop fills it".
  - When `cert_number` is null, show "รอร้านกรอกเลขใบรับรอง".
  - When it is filled, show it in full, with a verify link when the grader has one.
  - The order snapshot `detail` stores the cert masked to its last 4 characters ("•••• 4821").
- **Photos.** `public` disk, directory `graded-photos`. Files are deleted on delete or replace through `DB::afterCommit`, like `VariantPhoto`.
- **Admin `GradedItemResource`** (Catalogue group, after Variants):
  - **Form:**
    - printing select, searchable, with trashed-tolerant labels;
    - grader, grade, grade label, cert, subgrades (four optional decimals);
    - price via `MoneyInput`;
    - the three `FileUpload`s, image only;
    - status;
    - note.
  - **Table:**
    - card, code, grader + grade badge, price, status badge, created;
    - filters by status and grader;
    - a bulk action "ถอนออกจากหน้าร้าน" (withdraw).
  - **Policy:** staff only.
- **Storefront `/graded/{gradedItem}`** (`graded.show`, 404 unless `listed` or `reserved`; a `sold` item shows "ขายแล้ว" with no add button):
  - the three photos with a zoom (Alpine lightbox, reduced-motion safe, the real image, never card art);
  - the grader/grade badge, card name, code, language, rarity;
  - subgrades;
  - the cert line;
  - the guarantee line "ของแท้ 100% คืนได้ถ้าเคสหรือเกรดไม่ตรง";
  - price;
  - "ส่งแบบประกัน · กล่องแข็งกันกระแทก บังคับสำหรับของเกรด" with the insurance amount from config;
  - the add button (Livewire `Storefront\GradedBuyBox`), reusing `AddToCart` with qty 1. After adding it shows "อยู่ในตะกร้าแล้ว · ล็อกไว้ :minutes นาที".
  - A reserved item that is not in this cart shows "มีคนกำลังจองอยู่" and no add button.
- **Card page.** A section "การ์ดเกรดของการ์ดนี้" lists the card's `listed` graded items (tile: grader+grade badge, language, price, "ภาพจริงหน้า–หลัง", a link to `graded.show`). It is hidden when there are none.
- **Seeder.** `GradedItemSeeder` runs local only. It creates 6 listed items on seeded printings. Generate three placeholder images per item with GD (a plain labelled rectangle, "FRONT/BACK/LABEL · DEMO"), never real card art (rule), stored under `graded-photos/demo/`.

### Sealed products (Step B)

- **Enum `SealedKind`** (`booster_box`, `booster_pack`, `starter_deck`, `accessory`; Thai labels บูสเตอร์บ็อกซ์, บูสเตอร์แพ็ก, สตาร์ทเตอร์เด็ค, อุปกรณ์).
- **Table `sealed_products`.** Per data-model, with `softDeletes`, `is_active` (bool default true) and `description_th` (nullable text). `sku` unique, `slug` unique (generated from `name_en` like `Card`).
- **Purchasable:** active, not trashed, price not null, the game active when set, and not `is_preorder`. A pre-order product is bought only through Step C.
- **Holds.** `qty` with `stock_reservations`, exactly like variants: `availableQty` = qty − live holds. Add `scopeAvailable`/`withReservedQty` equivalents on `SealedProduct`.
- **Admin `SealedProductResource`.**
  - Form: kind, game, set (filtered by game), names, sku, price `MoneyInput`, qty, image upload (`public`/`sealed-photos`), description, active, is_preorder.
  - Table and filters, soft delete with restore.
- **Storefront `/p/{sealedProduct:slug}`** (`sealed.show`, 404 when inactive or trashed):
  - image, or a neutral placeholder block when it has none (not card art);
  - kind label, game and set, price, stock indicator (`<x-stock-indicator>`), description;
  - a quantity stepper capped at available;
  - add to cart (`Storefront\SealedBuyBox`, reusing `AddToCart`).
  - A pre-order product renders the Step C box instead. In Step B it shows "เปิดสั่งจองเร็ว ๆ นี้" and no button.
- **Search.**
  - `SealedProduct` gets Scout `Searchable` with its own index settings in `config/scout.php`.
  - `AutocompleteResult` gains `sealed` (max 2), and `CatalogueSearch` fills it from the engine, with the same database fallback pattern.
  - `search-box.blade.php` adds the group "สินค้ากล่อง" after sets, with rows linking to `sealed.show`.
  - Keyboard navigation includes the new rows. Tests use `SCOUT_DRIVER=null` plus the fallback.
- **Home.** If `home.blade.php` has a sealed or accessories section placeholder, fill it with up to 4 active in-stock sealed products. Otherwise add a section "สินค้ากล่อง" after the newest sets, styled like the existing tiles.
- **Seeder.** `SealedProductSeeder` creates 6 products across kinds on seeded games and sets, with an image-less placeholder.

### Pre-orders (Step C)

- **Table `preorders`.** Per data-model, plus `is_open` (bool) and `opens_at` (nullable). One row per sealed product (unique `sealed_product_id`).
  - A product is a pre-order when `is_preorder` is set and its `preorders` row is open, `release_on` is in the future, and `reserved_count < quota`.
- **Buying flow: no cart.** A pre-order is its own order, never mixed with stock items.
  - `/p/{slug}` shows `Storefront\PreorderBox`:
    - countdown to `release_on`: "เหลือเวลาสั่งจอง :d วัน :h ชั่วโมง :m นาที", Alpine, from the server time;
    - progress "จองแล้ว :n / :quota กล่อง" from `reserved_count`;
    - "มัดจำต่อกล่อง ฿ :deposit · ราคาเต็ม ฿ :full";
    - the two plans as radio cards: "มัดจำ ฿ :deposit วันนี้ · ส่วนที่เหลือ ฿ :rest ก่อนวันส่ง", and "จ่ายเต็ม ฿ :discounted (ลด :pct%) · ส่งรอบแรก";
    - quantity, capped at `min(per_account_limit − already reserved by this user, quota − reserved_count)`;
    - the refund line: "ยกเลิกได้ถึง :cancel_until คืนมัดจำเต็มจำนวน หลังจากนั้นมัดจำไม่คืน".
  - "สั่งจอง" requires login: a guest is sent to `login` with the intended URL. It then goes to `/preorder/{preorder}/checkout?qty=&plan=`, rendered by `Storefront\PreorderCheckout`, which reuses `CheckoutData` and the checkout fields, has no lock banner, and has its own summary.
- **`App\Actions\PlacePreorder::handle(Preorder, User, CheckoutData, int $qty, PreorderPlan $plan): Order`**, in one transaction:
  1. Lock the `preorders` row first.
  2. Re-check open/quota/release.
  3. Check `per_account_limit` against the sum of this user's non-cancelled/non-refunded pre-order qty for this pre-order, under the same lock.
  4. Increment `reserved_count`.
  5. Create the order:
     - new columns `kind` (`OrderKind`: `stock`/`preorder`, default `stock`), `preorder_plan` (`deposit`/`full`, nullable), `balance_due_satang` (default 0), `balance_requested_at` (nullable);
     - `payment_due_at` as for stock orders;
     - one `order_item` with `kind=sealed`.
  6. Create the first payment. For the deposit plan, `amount = deposit × qty`, the order total = the full price × qty and `balance_due_satang = total − deposit × qty`. For the full plan, the amount is the discounted total.
  7. Notify `OrderPlaced` (the copy adapts by kind).
- **Status.**
  - Deposit plan: `awaiting_payment` → (deposit confirmed) → new status `awaiting_balance` (label "รอชำระส่วนที่เหลือ") → (balance confirmed) `paid`. Full plan: `awaiting_payment` → `paid`.
  - `ConfirmPayment` decides by `receivedSatang()`: the deposit reached sets `awaiting_balance` with `paid_at` null, and the total reached sets `paid`. The existing rules for short slips and `PayShortfall` apply to each payment unchanged.
  - Packing and shipping proceed from `paid` only, and staff ship pre-orders after `release_on`. The desk shows "วางจำหน่าย :date".
- **Balance request.**
  - `App\Jobs\RequestPreorderBalances` runs daily at 09:00 in the shop timezone.
  - For each pre-order with `release_on` ≤ today + 7 and each order `awaiting_balance` with `balance_requested_at` null, under the order lock:
    - create a pending payment for `balance_due_satang` (PromptPay payload as usual);
    - set `payment_due_at = release_on` (end of day) and `balance_requested_at`;
    - notify `PreorderBalanceDue` (a `ShopNotification`, mail + LINE, with the signed order link).
  - The order page payment panel works unchanged on that payment.
- **Unpaid handling.**
  - `CancelUnpaidOrders` keeps cancelling unpaid deposits as usual (quota is returned).
  - For an `awaiting_balance` order past `payment_due_at`, it does not cancel automatically. Staff decide, and the desk shows it.
- **Cancel or refund of a pre-order** (`CancelOrder`/`RefundOrder` gain the pre-order branch, under the order lock, then the pre-order lock):
  - decrement `reserved_count`;
  - before or on `cancel_until`, the refund owed is `receivedSatang()`;
  - after it, the deposit portion is kept: owed = `receivedSatang() − deposit × qty`, never below 0.
  - The admin modal says which applies.
- **Admin.** A `PreorderResource` form on the sealed product: release, cancel_until, deposit and full via `MoneyInput`, discount bp, quota, per-account limit, open. The table shows progress.
  - Orders list filter by kind. The view page shows the plan, deposit, balance due and request state.
- **Copy** goes in `lang/th/*`, and every number is from data or config.

## Steps

### Step A — the Purchasable foundation and graded items (issue #53)

- Files: the contract, the DTO, the morph map, every action and view listed under the foundation decisions, the `order_items` migration, config, enums, model, migration, factory, seeder, policy, the Filament resource, the storefront page, the buy box, the card-page section, and lang.
- **Tests:**
  - **Regression.** Every existing cart, checkout and order test stays green unchanged, except where a snapshot column became nullable.
  - **Graded holds.**
    - Holding a graded item sets `reserved`, and a second cart is refused.
    - Release by job, by remove, and by expired renew sets `listed` again.
    - The self-heal case is purchasable.
    - Qty above 1 is refused.
  - **Placing an order.**
    - `PlaceOrder` sets graded `sold`.
    - The snapshot has `kind=graded` and `detail`.
    - `insurance_satang` = n × config, added to the total.
    - A mixed cart (variant + graded) locks in the fixed order, shown by the `DB::listen` pattern.
  - **Cancel and refund** return the item to `listed`, and variants still get their qty back.
  - **The listing rule** throws without photos, from a plain `create` (seeder path).
  - **The storefront page:**
    - 404 for withdrawn;
    - sold shows "ขายแล้ว";
    - "reserved by another" shows "มีคนกำลังจองอยู่";
    - the cert null/filled rendering;
    - the PSA verify link.
  - **Card page:** the graded section is listed-only.
  - **Admin:** the resource renders; create, edit and photo upload work; non-staff get 403; the withdraw bulk action.
  - **Order page and admin item list** render graded, single and sealed snapshots.
  - **Mutation runs:** the type-resolution in `ChangeCartQuantity`/`PlaceOrder` (a graded id equal to a variant id must not touch the variant), the listing rule, release flip, insurance count.

### Step B — sealed products (issue #54)

- Files: enum, migration, model, factory, seeder, policy, resource, storefront page and buy box, search index, autocomplete group, home section, lang.
- **Tests:**
  - holds and qty;
  - a mixed cart order decrements sealed `qty`;
  - cancel returns it;
  - an inactive/trashed/pre-order product is not purchasable;
  - the 404s;
  - the autocomplete group (database fallback);
  - keyboard rows;
  - the home section;
  - the admin resource and soft delete;
  - the slug and sku uniqueness;
  - the snapshot `kind=sealed`;
  - mutation runs on purchasability and the type resolution.

### Step C — pre-orders (issue #55)

- Files: migration (`preorders`, the order columns, the `OrderStatus::AwaitingBalance` case plus label and colour), models, enums (`OrderKind`, `PreorderPlan`), `PlacePreorder`, `PreorderBox`, `PreorderCheckout`, `ConfirmPayment` branch, `RequestPreorderBalances` + schedule, `PreorderBalanceDue`, the `CancelOrder`/`RefundOrder` branch, `CancelUnpaidOrders` rule, the admin resource and desk additions, lang.
- **Tests:**
  - the quota and per-account limit enforced under the lock (two concurrent attempts: the second refused when the quota is full; the `DB::listen` lock-first order);
  - `reserved_count` increments in the same transaction and returns on cancel or refund;
  - the deposit and full amounts and the discount;
  - the deposit confirm leads to `awaiting_balance`, and the balance confirm leads to `paid`;
  - the job requests once, 7 days out, creates the payment and notifies;
  - the refund before and after `cancel_until`;
  - a guest is redirected to login;
  - closed, full or released pre-orders refuse;
  - the countdown and progress render;
  - an `awaiting_balance` order is not auto-cancelled;
  - mutation runs on the limit, quota, lock order and cancel_until arithmetic.

## Order and done

A → B → C, one implementer round each.

- **Issues first:** open three `enhancement` issues in milestone "M8 · Graded and sealed product".
- **After each step:**
  - `verifier`: the full suite, Pint, build, `migrate:fresh --seed` (+ `scout:import` for B), and a browser run at 1440×900 and 390×844 with screenshots into `storage/screens/m8-<step>/`. Alpine and timing checks run 3×.
    - A: the graded page (zoom, cert states, sold, reserved by another), the card page section, cart and checkout with the insurance line, the order page, and the admin graded form with uploads (if the panel's 2FA blocks the browser, say so and rely on tests).
    - B: the sealed page, the stepper, add, search group, and home.
    - C: the pre-order box (countdown, progress, plans), the guest redirect, the pre-order checkout, the order page in `awaiting_balance`, the balance request (run the job with tinker), and the refund copy.
  - `reviewer` and `designer` on every step.
- **Fix the findings,** then commit per step: `feat(graded): …`, `feat(sealed): …`, `feat(preorder): …`. A bug in scope gets its own `bug` issue.
- **Side task for the agent-tuning session:**
  - Note any agent that says it lacked a tool, with the exact wording.
  - Note whether `reviewer` produces a silent-failure finding.
  - Note whether `designer`'s heuristic table was used.
  - Send a short message at the natural breaks.
- **M8 is done when** all M8 issues are closed and, on a seeded local app:
  - a customer buys a graded card (it shows reserved, then sold, with the insurance line);
  - a customer buys a booster box from stock found via the autocomplete;
  - a signed-in customer places a deposit pre-order that moves to `awaiting_balance`, gets the balance request from the job, and pays it, after which the order reaches `paid`.
