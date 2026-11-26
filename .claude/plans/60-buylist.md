# M9 · Buylist

> **สรุป (ไทย):** แบ่งเป็น 3 ขั้น ทำทีละขั้น
>
> **A — เครดิตร้าน**
> - **บัญชีเครดิต:** สมุดบัญชีแบบเพิ่มแถวอย่างเดียว (`store_credit_entries`) ทุกการเขียนผ่าน `RecordStoreCredit`
> - **ใช้ตอน checkout:** ลูกค้าที่ล็อกอินติ๊ก "ใช้เครดิตร้าน" ได้ ใช้ได้ไม่เกินยอดคำสั่งซื้อ
>   - ถ้าเครดิตจ่ายครบ คำสั่งซื้อเป็น "ชำระแล้ว" ทันทีโดยไม่ต้องสแกน QR
>   - ถ้ายกเลิกหรือคืนเงิน เครดิตที่ใช้ไปจะคืนเข้าบัญชีเครดิต
> - **หน้าบัญชี:** แสดงยอดเครดิตและประวัติทุกรายการ
> - **Admin:** หน้า Store credit ดูประวัติ และปรับยอดด้วยมือได้ (ต้องใส่เหตุผล)
>
> **B — ราคารับซื้อ และหน้า `/buylist`**
> - **Admin "Buy prices":** ราคาเงินสดและเครดิตต่อ printing ต่อสภาพต่อ finish, โควต้าต่อสัปดาห์, แก้หลายแถวพร้อมกัน, นำเข้า CSV
>   - เพิ่ม `finish` จาก docs เพื่อให้รับการ์ดฟอยล์เข้าสต็อกถูก variant
> - **หน้า `/buylist`:**
>   - ค้นทีละใบ หรือวางลิสต์ทั้งก้อน (ใช้ parser ของ deck importer)
>   - ใบที่ร้านไม่รับบอกเป็นบรรทัดเดียว โควต้าเต็มบอกว่า "ร้านรับซื้อครบโควตาสัปดาห์นี้"
>   - เลือกสภาพและจำนวน เห็นยอดเงินสดเทียบกับเครดิต พร้อมคำเตือนเรื่องการตรวจซ้ำ
> - **ส่งลิสต์:** ต้องล็อกอิน ส่งแล้วได้เลขใบรับซื้อ ราคาเสนอใช้ได้ 7 วัน พร้อมวิธีแพ็กและที่อยู่ร้าน (จาก config)
>
> **C — ตรวจซ้ำและจ่ายเงิน**
> - **Admin:** รับของ → ประเมินสภาพทีละบรรทัด (ถ้าสภาพต่ำกว่าที่ลูกค้าเลือกต้องแนบรูป) → ยอดคำนวณใหม่ทันที
> - **ยอดลดลง:** สถานะเป็น `revised` ลูกค้าได้รับแจ้ง แล้วเลือกยืนยันราคาใหม่หรือขอการ์ดคืนฟรี ระหว่างนี้ไม่มีปุ่มจ่าย
> - **ยอมรับ:** การ์ดเข้าสต็อก (สร้างหรือเพิ่ม variant) ใน transaction เดียวกับการจ่าย
>   - เลือกเครดิต: เครดิตเข้าทันที
>   - เลือกเงินสด: staff บันทึกเลขอ้างอิงการโอน
> - **Dashboard:** widget "ใบรับซื้อที่รอการ์ดมาถึง"
>
> **ไม่ทำ:** ใช้เครดิตกับพรีออเดอร์, คืนการ์ดบางใบ (คืนทั้งใบรับซื้อเท่านั้น), หน้า Users ใน admin, การโอนเงินอัตโนมัติ

## Context

M9 in `docs/README.md` is "Buylist — buy prices, submissions, re-inspection, store credit". It is the last milestone. The goal is that a customer knows what they will be paid **before** they pack a parcel, and that the shop's re-inspection is honest and visible.

Specs:
- `docs/accounts-line-buylist.md` § Store credit and § The buylist (prices, submitting, re-inspection, paying).
- `docs/data-model.md` § Buylist and `users.store_credit_satang`.
- `docs/admin.md` § Buylist desk, the navigation groups "Buylist: Buy prices, Submissions" and "Customers: Store credit", and § Widgets.
- `docs/architecture.md`:
  - `AcceptBuylistSubmission` and spending credit are actions;
  - the test "a buylist submission re-assessed at a lower condition needs the customer's confirmation before it can be paid".
- `docs/storefront.md`: `/buylist` (`buylist`) and `Storefront\BuylistBuilder` ("the lines, cash-or-credit, the running total").

Design copy, from `storage/canvas-ref/project/`:
- **Flows C:**
  - "ค้นการ์ดที่จะขาย · ทีละใบ หรือวางลิสต์ทั้งก้อนแบบเด็ค"
  - "เห็นราคารับซื้อ · เงินสด vs เครดิตร้าน (เครดิตสูงกว่า 15%)"
  - "เลือกสภาพเอง · พร้อมคำเตือนว่าร้านตรวจซ้ำเมื่อของถึง"
  - "ส่งลิสต์ · ได้ใบจอง + วิธีแพ็กและที่อยู่ส่งการ์ด"
  - "ร้านตรวจและจ่าย · เครดิตเข้าทันที เงินสดโอนภายใน 1 วัน"
  - "สภาพไม่ตรง → ร้านเสนอราคาใหม่พร้อมรูปถ่าย ลูกค้ายืนยัน หรือขอการ์ดคืนฟรี"
- **Desktop top bar:** "รับซื้อการ์ด". **MHome tools:** "ขายการ์ดให้ร้าน". **Sitemap:** account sub-item "เครดิตร้าน".
- **States, empty buylist:** "ลิสต์ขายยังว่าง" / "ค้นทีละใบก็ได้ แต่ถ้ามีหลายสิบใบ แปะลิสต์เร็วกว่ามาก" / button "วางลิสต์ทั้งก้อน".
- **Docs:** "สภาพที่คุณเลือกเป็นการประเมินเบื้องต้น ร้านจะตรวจซ้ำเมื่อการ์ดถึง ถ้าไม่ตรง ร้านจะส่งรูปและราคาใหม่ให้ยืนยัน หรือส่งการ์ดคืนฟรี", "ร้านรับซื้อครบโควตาสัปดาห์นี้", "ค้นไม่เจอแปลว่าตอนนี้ยังไม่รับใบนั้น".
- **No artboard** for the buylist page itself. Build it from the existing components and tokens, as the deck and account pages were.

The user delegated every decision.

## Rules in force

Every rule in `.ai/rules/project.md` applies. These matter most here:
- Money in satang via `Money`/`<x-price>`/`MoneyInput`.
- Never print an invented price or address: the shop's receiving address comes from config.
- Lock first.
- Guard filters are re-evaluated under the row lock.
- Locks on `payments` come after every other row lock.
- `Order::receivedSatang()` is the money in.
- Orders snapshot what they bought; so do submissions.
- One gold button per screen.
- Row actions sit in their own row.
- A soft-disabled control must answer.
- Stale results.
- `#[Computed]` is per request: expensive work runs in a throttled action and is kept in locked state.
- A Livewire `render()` never passes view data under a public property's name.
- `DateTimePicker` timezone.
- Filament `afterSave` is in a transaction.
- An upload checks that the file exists.
- Policies on every resource.
- The trashed-parent rule.
- A phone fixed bar never hides on focus and uses `scroll-padding-bottom`.
- `pull()` for after-login session values.
- Jobs set `$tries`.
- `ShopNotification` with email fallback.

Before writing code:
- Use Boost `search-docs` (Filament 5 resources, table actions, FileUpload, importers, widgets, `TextInputColumn`; Eloquent `lockForUpdate`; the scheduler).
- Load the skills `laravel-best-practices`, `livewire-development`, `tailwindcss-development` and `testing-best-practices`.

Pint applies. The implementer does not commit. Run mutation runs against the whole suite.

## Decisions (do not reopen)

### Global lock order

Purchasables (variants → graded → sealed, by id) → `buylist_prices` (by id) → `buylist_submissions` → `orders` → `preorders` → `users` (credit) → `payments`.

Every new action takes its locks in this order. `RecordStoreCredit` locks the user row itself, so a caller that also locks orders or purchasables must have taken those first.

### Step A — store credit

- **Ledger table and enum.**
  - Migration `store_credit_entries`: `id`, `user_id` fk cascade, `delta_satang` (signed integer), `reason` (`StoreCreditReason` enum: `buylist`, `order`, `refund`, `adjustment`, labels "ขายการ์ดให้ร้าน", "ใช้ซื้อสินค้า", "คืนจากคำสั่งซื้อ", "ปรับยอดโดยร้าน"), `source_type`/`source_id` (nullable morph, aliases `order` and `buylist_submission` added to the morph map), `note` (nullable string, 255; required for `adjustment`), `balance_after_satang`, `created_by` (nullable fk users, the staff member for adjustments), `created_at` only (no `updated_at`; the model sets `const UPDATED_AT = null`).
  - Index `(user_id, id)`.
  - The model has no update or delete path. `updating` and `deleting` hooks throw `LogicException`.
- **`App\Actions\RecordStoreCredit::handle(User $user, int $deltaSatang, StoreCreditReason $reason, ?Model $source = null, ?string $note = null, ?User $staff = null): StoreCreditEntry`.**
  - It runs inside `DB::transaction` (nested when the caller already has one).
  - It locks the user row (`User::query()->lockForUpdate()->findOrFail`) and reads `store_credit_satang` from the locked row.
  - The new balance is `old + delta`. If that is below 0 it throws `App\Exceptions\InsufficientStoreCredit`.
  - It refuses a delta of 0 and an `adjustment` without a note (`InvalidArgumentException`).
  - It writes the entry with `balance_after_satang`, then sets the cache column with `forceFill(...)->save()`.
  - Nothing else writes `store_credit_satang`. Add it to no `$fillable`.
- **Order money helpers** (`app/Models/Order.php`):
  - Add `payableSatang(): int` = `max(0, total_satang - credit_used_satang)`: what must arrive as money.
  - `remainingSatang()` becomes `max(0, payableSatang() - receivedSatang())`.
  - `ConfirmPayment` compares `$received >= $order->payableSatang()` instead of `total_satang`.
  - The implementer greps every other `total_satang` comparison in payment logic (`SubmitSlip`, `RejectPayment`, `PayShortfall`, `SlipLimit`, `PaymentPanel`, `OrderPlaced`, the payment desk) and switches each one that means "money due" to `payableSatang()`.
  - `total_satang` keeps meaning the order's full value, and displays still show it.
  - `refundOwedSatang()` is unchanged: it is cash, and credit goes back as credit.
- **Checkout** (`CheckoutForm` + view, `CheckoutData`, `PlaceOrder`):
  - **The checkbox.** A signed-in customer with `store_credit_satang > 0` sees, above the payment method, the checkbox card "ใช้เครดิตร้าน" with the sub-line "มีเครดิต ฿ :balance · ใช้ได้ ฿ :usable กับคำสั่งซื้อนี้". It is off by default.
    - `CheckoutData` gains `bool $useCredit = false`.
    - The summary shows the line "เครดิตร้าน −฿ :amount" and the total line becomes "ยอดที่ต้องโอน".
    - Guests never see it.
  - **`PlaceOrder`.** After `PurchasableTypes::lockMany` and before the order insert:
    - when `$data->useCredit && $user`, it locks the user row and computes `credit = min(balance, total)`;
    - after the insert, it calls `RecordStoreCredit` with `-credit`, reason `order`, source the order;
    - it sets `credit_used_satang`.
  - **The payment row.** It is created for `payableSatang()` when that is above 0.
  - **Credit covers it all.** When `payableSatang()` is 0, no payment row is created, and the status is `Paid` with `paid_at` = now. `OrderPlaced` is sent as usual, and the order page shows "ชำระด้วยเครดิตร้านครบแล้ว" instead of the payment panel.
  - **Pre-orders** (`PlacePreorder`, `PreorderCheckout`) do not offer credit. Out of scope.
- **Cancel or refund** (`CancelOrder`, `RefundOrder`). After the order lock (and the pre-order lock), before the payment updates:
  - when `credit_used_satang > 0`, call `RecordStoreCredit(+credit_used_satang, refund, source order)` inside the same transaction;
  - set a new column `orders.credit_returned_at` so it can never return twice. A second cancel is already refused by status; the column is the belt.
  - `RefundResult` gains `creditReturnedSatang`.
  - The admin modal copy and the `OrderCancelled`/`OrderRefunded` notifications add the line "คืนเครดิตร้าน ฿ :amount" when it is above 0.
  - `CancelUnpaidOrders` goes through `CancelOrder`, so the credit comes back automatically.
- **Account page.** A section "เครดิตร้าน" placed after the orders:
  - the balance in large type (`<x-price>`);
  - the line "เครดิตไม่มีวันหมดอายุ ใช้ได้ตอนชำระเงิน";
  - the latest 20 entries: date, reason label, the source link (order number → `order.show`, submission number → `buylist.show`, added in B, shown as plain text until then), the delta (green +, ink −) and the balance after;
  - the empty state "ยังไม่มีเครดิตร้าน" with the hint "ขายการ์ดให้ร้านแล้วเลือกรับเป็นเครดิต ได้มากกว่าเงินสด" and a link to `buylist` (added in B; in A link to `home`, and B changes it).
- **Admin `StoreCreditEntryResource`** (group Customers, label "Store credit"):
  - **The table.** It is read-only: user (name or email), reason badge, delta, balance after, source, staff, created. Filters by reason and by user (searchable). Newest first.
  - **The header action "ปรับยอดเครดิต".** It takes a user select (searchable, customers only, `is_staff = false`), a direction (เพิ่ม/ลด), an amount `MoneyInput` (> 0) and a required note. It calls `RecordStoreCredit` and shows the `InsufficientStoreCredit` error as a notification.
  - No edit, no delete. The policy allows staff only.
- **Seeder.** `StoreCreditSeeder`, local only: the seeded known-password customer gets an `adjustment` of ฿500 with the note "เครดิตทดลอง".

### Step B — buy prices and the `/buylist` builder

- **Table `buylist_prices`:**
  - Columns: `id`, `printing_id` fk, `condition` (`CardCondition`), `finish` (`Finish`, default `normal`), `cash_satang`, `credit_satang`, `weekly_quota` (nullable unsigned int; null means no cap), `accepted_this_week` (unsigned int, default 0), `is_active` (bool, default true), timestamps.
  - Unique `(printing_id, condition, finish)`.
  - **This deviates from the docs.** The docs have `(printing_id, condition)`, but a foil printing needs its own price and its own target variant. Update `docs/data-model.md` in the same change.
  - **What `accepted_this_week` counts:** quantity **committed** this week. It is incremented when a submission is submitted, and decremented when a line is returned or cut. It is reset to 0 every Monday at 00:00 shop time by a scheduled job, `ResetBuylistQuotas` (`$tries` 3).
  - `remainingQuota(): ?int` = `weekly_quota - accepted_this_week`, floored at 0, or null when there is no cap.
- **Config `config/buylist.php`:**
  - `credit_bonus_bp` (env `BUYLIST_CREDIT_BONUS_BP`, default 1500);
  - `quote_days` (7);
  - `receiving_address` (env `SHOP_BUYLIST_ADDRESS`, nullable, multi-line);
  - `max_lines` (100);
  - `max_qty_per_line` (50).
- **Admin `BuylistPriceResource`** (group Buylist, label "Buy prices"), mirroring the Variants resource's patterns (`app/Filament/Resources/Variants/*`, `BulkEditVariants`, `VariantImporter`):
  - **The table:** card, code, language, condition badge, finish, cash, credit, quota, taken this week, active.
    - Filters: game, set, condition, active.
    - The bulk action "แก้ราคารับซื้อ": set cash, set credit, set quota, active on/off, for the selected rows.
    - The bulk action "ตั้งเครดิต = เงินสด + 15%", which rounds to whole baht and uses `credit_bonus_bp`.
  - **The form:** a printing select (searchable, trashed-tolerant labels), condition, finish (only `foil` when the printing `has_foil`), cash `MoneyInput`, credit `MoneyInput` (when left blank on create it is filled with cash × (1 + bonus) in whole baht), quota, active.
  - **The CSV importer.** Columns: `set_code`, `number`, `language`, `condition`, `finish`, `cash_baht`, `credit_baht`, `weekly_quota`, `is_active`. It upserts on the unique key and follows the importer rule about `columnMap`.
  - The policy allows staff only.
- **Model `BuylistPrice`:**
  - `scopeBuyable`: `is_active`, the printing `searchVisible()`, and `cash_satang > 0`.
  - The relation `printing` tolerates a trashed parent.
  - `unitFor(BuylistPayout $payout): int`.
- **Enums.**
  - `BuylistPayout`: `cash` → "เงินสด", `credit` → "เครดิตร้าน".
  - `BuylistStatus`: `draft`, `submitted`, `received`, `revised`, `accepted`, `paid`, `returned`, with labels "ร่าง", "รอการ์ดมาถึง", "ได้รับการ์ดแล้ว · กำลังตรวจ", "รอคุณยืนยันราคาใหม่", "ยืนยันแล้ว · รอจ่าย", "จ่ายแล้ว", "ส่งการ์ดคืน" and badge colours following `OrderStatus`. `draft` is never written, since the builder keeps its state in the session; the case exists because the docs list it.
- **Tables `buylist_submissions` and `buylist_submission_items`:**
  - **Submissions** follow the data-model, plus:
    - `received_at`, `revised_at`, `responded_at`, `accepted_at`, `paid_at`, `returned_at` (nullable timestamps);
    - `late_note` (nullable);
    - `payout_reference` (nullable, for the cash transfer);
    - `staff_note` (nullable);
    - `return_tracking` (nullable).
    - `number` is `BUY-` + the id, padded to 5, via `numberFor()` like `Order`.
  - **Items** follow the data-model, plus:
    - `buylist_price_id` (nullable fk, null on delete);
    - `finish`;
    - the snapshot columns `card_name`, `display_code`, `language`, `quoted_line_satang`;
    - `assessed_line_satang` (nullable).
  - The photo is on the `public` disk under `buylist-photos/`. Files are deleted through `DB::afterCommit` when they are replaced, as `VariantPhoto` does.
  - Index `(user_id, id)` on submissions, and `(status, id)`.
- **Livewire `Storefront\BuylistBuilder`** at `/buylist` (route `buylist`; the full page `buylist.blade.php` via a controller like `DeckController`). It is open to guests.
  - **Two modes, as a segmented control:** "ค้นทีละใบ" and "วางลิสต์ทั้งก้อน".
    - **Search mode.** A text input with `wire:model.live.debounce.300ms`. Results come from `BuylistPrice::buyable()` joined to printings `matchingText()` (the existing scope on `Printing`), at most 10 printings, each row showing the card, code, language and "รับซื้อสูงสุด ฿ :cash · เครดิต ฿ :credit" (the best condition).
      - With no results: "ค้นไม่เจอแปลว่าตอนนี้ร้านยังไม่รับใบนั้น".
      - The query runs in the render path, but it is a cheap indexed DB query: no Meilisearch, no throttle.
    - **Paste mode.** A game select plus a textarea, then the button "ตรวจราคารับซื้อ".
      - It runs `ParseDeckList::match()` (`app/Actions/ParseDeckList.php:64`) in the action, throttled like `DeckImport::throttled()` (its own key `buylist-check:`).
      - Each matched line's `printingIds` resolve to the first printing that has a buyable price. That printing is added as a line, with qty = the parsed qty and the condition = the best condition offered.
      - Unmatched or unbought lines are listed under "ร้านยังไม่รับใบเหล่านี้" (the line text, up to 20, then "+ อีก :n บรรทัด").
      - The result is kept in `#[Locked]` state, per the computed rule.
  - **Lines.** Each line is a bordered row:
    - placeholder art (`<x-card-art>`), name, code and language;
    - a condition select listing only the conditions that have a buyable price row for that printing and finish, each option showing its cash price;
    - a qty stepper (1 to `min(max_qty_per_line, remainingQuota)`);
    - the cash and credit line totals;
    - a remove link.
    - When `remainingQuota() === 0` the line shows "ร้านรับซื้อครบโควตาสัปดาห์นี้", is excluded from the totals, and is not submitted. When the qty exceeds the remaining quota it is capped, with the note "รับได้อีก :n ใบสัปดาห์นี้".
    - Adding a printing already in the list increments that line.
  - **State.** Lines are `{buylist_price_id, qty}` in a public array, mirrored to `session('buylist.lines')` on every change, so a guest who logs in comes back to the same list. `mount` reads it with `get()`. This is not an after-login one-shot value; it is the builder's own state and is cleared after submit.
  - **The empty state** (from States): "ลิสต์ขายยังว่าง" / "ค้นทีละใบก็ได้ แต่ถ้ามีหลายสิบใบ แปะลิสต์เร็วกว่ามาก" / the secondary button "วางลิสต์ทั้งก้อน", which switches mode.
  - **The payout choice.** Two radio cards:
    - "เงินสด ฿ :cash · โอนภายใน 1 วันทำการหลังร้านตรวจ";
    - "เครดิตร้าน ฿ :credit · ได้มากกว่า ฿ :diff · เข้าบัญชีทันทีหลังร้านตรวจ".
    - The default is credit.
  - **The warning,** verbatim from the docs, always shown above submit: "สภาพที่คุณเลือกเป็นการประเมินเบื้องต้น ร้านจะตรวจซ้ำเมื่อการ์ดถึง ถ้าไม่ตรง ร้านจะส่งรูปและราคาใหม่ให้ยืนยัน หรือส่งการ์ดคืนฟรี". Below it: "ราคานี้ยืนยันให้ :days วันนับจากวันส่งลิสต์".
  - **Submit.** The single gold button "ส่งลิสต์ขาย · ฿ :total".
    - A guest sees "เข้าสู่ระบบเพื่อส่งลิสต์" instead, linking to `login` with the intended URL `buylist`.
    - It is `aria-disabled` with a reason when there are no submittable lines.
    - On a phone it is a fixed bottom bar `#buylist-bar` with `scroll-padding-bottom` in `resources/css/app.css`, per the rule.
  - **Price drift.** The totals are recomputed from the current price rows on every render. If a price changed between render and submit, the action refuses with "ราคารับซื้อเปลี่ยนแล้ว ตรวจยอดใหม่อีกครั้ง". The guard compares the full `price_id → (unit, qty)` signature, as the add-all rule does.
- **`App\Actions\SubmitBuylist::handle(User $user, BuylistPayout $payout, array $lines, string $shownSignature): BuylistSubmission`.** One transaction:
  1. Lock the `buylist_prices` rows by id.
  2. Re-check each row is `buyable`, and refuse the whole submit on a signature mismatch (`App\Exceptions\BuylistQuoteChanged`).
  3. Check quota under the lock. A line above the remaining quota refuses with `BuylistQuotaFull` naming the card. No silent capping in the action: the UI capped first.
  4. Increment `accepted_this_week`.
  5. Create the submission: status `submitted`, `quoted_total_satang`, `quote_expires_at` = now + `quote_days`, end of that day in shop time.
  6. Create the items: `quoted_unit_satang` from `unitFor($payout)`, the snapshot columns, and `claimed_condition` = the row's condition.
  - It refuses an empty list, more than `max_lines` lines, or a qty below 1 or above `max_qty_per_line`.
  - It refuses a staff user, since staff do not sell to their own shop through the storefront.
  - After commit: notify `BuylistSubmitted` (a `ShopNotification`: mail plus LINE, "ได้รับลิสต์ขาย :number แล้ว ยอดเสนอ ฿ :total ยืนยันถึง :date", with a link to `buylist.show`). Clear `session('buylist.lines')`.
- **The page `/buylist/{submission:number}`** (`buylist.show`, `auth`, owner only; anyone else gets a 404):
  - the number, a status badge and a timeline (submitted, received, revised, accepted, paid, or returned);
  - "ราคาเสนอยืนยันถึง :date";
  - **Packing instructions** (lang):
    - "ใส่การ์ดในซองใสและท็อปโหลดเดอร์ทีละใบ"
    - "แนบกระดาษเขียนเลขใบรับซื้อ :number ไว้ในกล่อง"
    - "ห่อกันกระแทกและส่งแบบมีเลขพัสดุ"
  - **The receiving address** from `config('buylist.receiving_address')`. When it is empty: "ร้านจะแจ้งที่อยู่สำหรับส่งการ์ดทาง LINE หรืออีเมล".
  - the lines (claimed condition, qty, unit, line total), and the payout and total;
  - the Step C sections.
- **Account page.** A section "ลิสต์ขายการ์ด": the latest 10 submissions (number, date, status badge, the quoted or final total, a link). The empty state links to `buylist`. The credit empty state now links to `buylist`.
- **Navigation.**
  - In the desktop header, "รับซื้อการ์ด" after the deck link (`aria-current` on `buylist*`).
  - On the phone, if `home.blade.php` has a tools row with "ขายการ์ดให้ร้าน", link it to `buylist`; otherwise add that link to the home tools list where "วางเด็คลิสต์" is.
  - The phone bottom nav is unchanged.
- **Seeder.** `BuylistPriceSeeder`: buy prices for about 30 seeded printings across conditions NM/LP/MP, cash at about 40-60% of the variant price, credit with the bonus, and quotas on a few. It also seeds one `submitted` submission for the demo customer (local only).

### Step C — re-inspection, accept, pay

- **The admin `BuylistSubmissionResource`** (group Buylist, label "Submissions"):
  - **The table:** number, customer, payout, status badge, quoted, final, quote expires, submitted.
    - The status filter defaults to the open statuses.
    - Oldest first.
  - **The view/edit page.** It has the header actions below, plus an items repeater, read-only except for the assessment fields.
  - **Each line shows:**
    - card, code, qty;
    - the claimed condition badge and the quoted unit;
    - the assessed condition select (default = claimed);
    - the assessed unit, recalculated live (`->live()`);
    - the note;
    - a photo `FileUpload`, **required when the assessed condition ranks below the claimed one** (use the `CardCondition` order);
    - the line's new total.
  - The form footer shows "ยอดเสนอเดิม ฿ :quoted → ยอดใหม่ ฿ :final".
- **The assessed unit rule** (`App\Support\BuylistAssessment::unitFor(item, assessedCondition)`):
  - For the claimed condition: `quoted_unit_satang`. The quote is honoured, even if the price has changed since.
  - For a lower condition: the current buyable price row for (printing, assessed condition, finish) under the payout, capped at `quoted_unit_satang`.
  - With no such row: 0, and the line notes "ร้านไม่รับสภาพนี้". A 0 line counts as a reduction.
  - Staff can never assess above the claimed condition. Higher conditions are not offered in the select.
- **Actions.** Each locks the submission row first; the order above applies to the rest.
  - **`ReceiveBuylistSubmission(submission, ?lateNote)`** moves `submitted` → `received` and sets `received_at`.
    - If now is after `quote_expires_at`, `lateNote` is required, and the header action's modal shows the field "เหตุผลที่ยังรับราคาเดิม (การ์ดถึงหลังวันหมดอายุ)".
  - **`AssessBuylistSubmission(submission, array assessments)`** applies only from `received`.
    - It writes the assessed condition, unit, note, photo and line total, and `final_total_satang`.
    - If any line's assessed line total is below its quoted line total, the status becomes `revised`, `revised_at` is set, and the customer is notified with `BuylistRevised` ("ร้านตรวจการ์ดใน :number แล้ว มี :n ใบที่สภาพไม่ตรง ยอดใหม่ ฿ :final กรุณายืนยันหรือขอการ์ดคืน" plus the link).
    - Otherwise the status stays `received`, ready to accept.
    - Saving the edit form calls it. Since `afterSave` runs in a transaction, the action takes its own locks in order, with the submission first.
  - **`RespondToBuylistRevision(submission, User $customer, bool $accept)`** is called by the customer on `buylist.show`, from `revised` only, by the owner only.
    - Accept moves it to `accepted` with `responded_at`, then runs `AcceptBuylistSubmission` in the same call.
    - Return moves it to `returned` with `responded_at` and `returned_at` left null (set when shipped back), and gives the quota back.
  - **`AcceptBuylistSubmission(submission, ?User $staff)`** applies from `received` (no reduction) or from the customer's accepted revision. It never applies from `revised`: it throws `BuylistAwaitingCustomer`. In one transaction:
    1. Lock the target variants: for each line, `Variant` where printing, assessed condition and finish match. Missing ones are created first, in a separate preceding step that is idempotent through the unique index (`firstOrCreate` with the price null, qty 0), then all are locked by id.
    2. Lock the submission and re-check its status.
    3. Increment each variant's `qty` by the line qty. A line assessed at 0 is not absorbed: it is returned with the parcel, so set its note.
    4. Payout:
       - **Credit:** `RecordStoreCredit(+final_total, buylist, source submission)`, then status `paid` with `paid_at` and `accepted_at`.
       - **Cash:** status `accepted`, `accepted_at`.
    5. After commit: `VariantAlerts::dispatchFor(variantIds)` (restock alerts fire for 0 → >0), and notify `BuylistPaid` (credit: "เครดิต ฿ :amount เข้าบัญชีแล้ว") or `BuylistAccepted` (cash: "ร้านยืนยันยอด ฿ :amount แล้ว จะโอนภายใน 1 วันทำการ").
    - Variants created here are unpriced, so they stay unbuyable until staff price them. That is the existing purchasability rule, and the success notification in admin says "สร้าง variant ใหม่ :n รายการ ยังไม่มีราคาขาย".
  - **`MarkBuylistPaid(submission, string reference, User $staff)`** moves `accepted` with payout cash to `paid`, and sets `payout_reference` and `paid_at`. It notifies `BuylistPaid` ("โอนเงิน ฿ :amount แล้ว อ้างอิง :ref").
  - **`ReturnBuylistSubmission(submission, ?string tracking)`** applies from `submitted`, `received` or `revised` (the staff side), and also records the tracking for one the customer returned.
    - It sets `returned`, `returned_at` and `return_tracking`.
    - It decrements `accepted_this_week` (floored at 0) on each locked price row by the item qty, unless already done by `RespondToBuylistRevision`. Track this with a `quota_released_at` column so it is released once.
- **Header actions, by status:**

  | Status | Actions |
  |---|---|
  | `submitted` | "ได้รับการ์ดแล้ว", "ส่งคืน" |
  | `received` | "บันทึกการตรวจ" (form save); "ยืนยันและจ่าย", only when no line is reduced; "ส่งคืน" |
  | `revised` | none of pay, accept or edit; a callout "รอลูกค้ายืนยันราคาใหม่"; "ส่งคืน" |
  | `accepted` (cash) | "บันทึกการโอน" (reference required) |
  | `returned` with no tracking | "บันทึกเลขพัสดุส่งคืน" |

  - **This is the docs' rule:** the resource never offers pay while `revised`.
  - Every action is confirmed and names the submission.
- **The customer page in `revised`.**
  - Each changed line shows claimed versus found (condition badges), the photo (click to zoom; reuse the graded lightbox pattern), the shop note, and the old and new unit.
  - It shows the new total versus the quoted one.
  - Two buttons: "ยืนยันราคาใหม่ ฿ :final" (gold), and "ขอการ์ดคืนฟรี" (secondary, confirmed).
  - In other states the page shows the status copy:
    - received: "ร้านได้รับการ์ดแล้ว กำลังตรวจสภาพ";
    - accepted (cash): "ร้านยืนยันยอดแล้ว จะโอนภายใน 1 วันทำการ";
    - paid: "จ่ายแล้ว" plus "เครดิตเข้าบัญชี" or "อ้างอิงการโอน :ref";
    - returned: "ร้านจะส่งการ์ดคืนให้ฟรี", plus the tracking when it is set.
- **Widget `BuylistAwaitingCardsWidget`.** A stat, "ใบรับซื้อที่รอการ์ดมาถึง", counting `submitted`, with a description naming the oldest submission's age. A second stat counts `revised` awaiting the customer. It links to the resource, filtered. Gate: staff.
- **Customer notifications.** All go through `ShopNotification`, snapshot their numbers when they are created, set `$tries` as the existing ones do, and use lang keys in `lang/th/notifications.php`.

## Steps

### Step A — store credit (issue A)

- **Files:**
  - the migration (entries, plus `orders.credit_returned_at`), `StoreCreditEntry` model and factory, `StoreCreditReason`;
  - `RecordStoreCredit`, `InsufficientStoreCredit`;
  - `Order::payableSatang` and its callers, `CheckoutData`, `CheckoutForm` and its view;
  - `PlaceOrder`, `CancelOrder`, `RefundOrder`, `RefundResult`;
  - the notifications, the order page (paid by credit), the account section;
  - the Filament resource, policy and seeder, and lang.
- **Tests:**
  - **The ledger.** `RecordStoreCredit` writes the entry and the cache together. It refuses below zero, refuses a zero delta, and refuses an adjustment without a note. It cannot be updated or deleted.
  - **Concurrency.** Two concurrent spends cannot overdraw: show it with the lock-first `DB::listen` pattern (the user row's `for update` before the entry insert).
  - **Checkout.**
    - The credit checkbox is hidden for guests and for a zero balance.
    - A partial spend gives a payment of `total − credit`, `credit_used_satang` set, and an `order` ledger entry with source.
    - Full cover: no payment, status `paid`, the order page shows the paid-by-credit copy.
    - A confirm of a payment for `payableSatang()` makes it `paid`, not short.
  - **Cancel and refund.**
    - Cancel (unpaid, via `CancelUnpaidOrders`) returns the credit once.
    - Refund returns it and the cash amount is unchanged.
    - A second attempt does not double it.
  - **Account page:** the balance, the entries with source links, the empty state.
  - **Admin:** the resource renders; the adjustment action works and refuses overdraw; non-staff get 403.
  - **Mutation runs:** the `min(balance,total)` cap, `payableSatang` in `ConfirmPayment`, the return-once guard, the negative-balance refusal.

### Step B — buy prices and the builder (issue B)

- **Files:** the migrations, `BuylistPrice`, the submission models, factories and seeders; the enums and config; `BuylistPriceResource` with its importer and bulk actions; `ResetBuylistQuotas` and its schedule; `BuylistBuilder` and its views, the controller, routes and CSS; `SubmitBuylist` and its exceptions; `BuylistSubmitted`; `buylist.show` (without the Step C sections); the account section, the nav, lang, and the docs data-model update.
- **Tests:**
  - **The buyable scope:** inactive, trashed printing, inactive game.
  - **Search:**
    - search finds only buyable printings;
    - "not bought" shows the one-liner, not a zero.
  - **Paste:**
    - paste mode adds matched bought lines and lists the others;
    - it is throttled.
  - **Lines:**
    - the condition select offers only priced conditions;
    - the quota-full copy;
    - the qty cap note.
  - **Totals:** cash and credit, and the credit diff.
  - **Session:** the lines survive login.
  - **The guest submit link.**
  - **`SubmitBuylist`:**
    - snapshot columns;
    - unit by payout;
    - `quote_expires_at`;
    - `accepted_this_week` incremented;
    - quota refusal under the lock (the `DB::listen` lock-first order);
    - the signature mismatch refuses;
    - empty, oversize and staff refusals;
    - the notification is sent.
  - **The quota reset job:** it resets on schedule.
  - **`buylist.show`:**
    - owner only (404 for another user, a redirect to login for a guest);
    - the receiving address from config, or the fallback line.
  - **Admin:**
    - the resource renders;
    - the bulk edit and the +15% action;
    - the importer upsert and the unmapped-column rule;
    - the unique key;
    - 403 for non-staff.
  - **Mutation runs:** the quota arithmetic, the buyable scope, the unit-by-payout, the signature check.

### Step C — re-inspection and paying (issue C)

- **Files:** `BuylistSubmissionResource` (pages, the form, the actions), `BuylistAssessment`; the actions Receive, Assess, Respond, Accept, MarkPaid and Return; the exceptions; the notifications Revised, Accepted and Paid; the `buylist.show` Step C sections plus a Livewire `Storefront\BuylistRevisionBox` for the two buttons; the widget, the migration additions (`quota_released_at`), and lang.
- **Tests:**
  - **The docs test:** a submission re-assessed at a lower condition is `revised`, and `AcceptBuylistSubmission` throws until the customer confirms. The admin page offers no pay action while `revised`.
  - **The assessed unit:**
    - claimed is honoured;
    - lower uses the current price, capped;
    - no row gives 0;
    - higher than claimed is refused.
  - **The photo rule:** required when lower.
  - **A late receive** requires a note.
  - **Accept with credit:**
    - the ledger entry has the source;
    - status `paid`;
    - variants incremented;
    - missing variants are created unpriced and are not purchasable;
    - a restock alert fires for 0 → >0;
    - one transaction: a forced failure after the stock step leaves no credit and no stock.
  - **Cash:** accept gives `accepted`; mark-paid needs a reference and gives `paid`.
  - **The customer side:**
    - confirms → paid or accepted;
    - returns → returned with the quota released once;
    - only the owner can respond;
    - only from `revised`.
  - **A staff return** releases the quota once.
  - **The widget counts.**
  - **The lock order** (`DB::listen`): variants → submission → users in accept.
  - **Mutation runs:** the reduction check (`<` versus `<=`), the cap, the status guards, the quota release once.

## Order and done

A → B → C, one implementer round each.

- **Issues first:** open three `enhancement` issues in milestone "M9 · Buylist".
- **After each step:**
  - `verifier`: the full suite, Pint, build, `migrate:fresh --seed`, and a browser run at 1440×900 and 390×844 with screenshots into `storage/screens/m9-<step>/`. Alpine and timing checks run 3×.
    - A: checkout with credit (partial and full), the order page paid by credit, the account credit section, the admin adjustment.
    - B: `/buylist` search, paste, quota-full and empty states, the payout cards, the phone bar, the guest submit, submit, and the confirmation page.
    - C: admin receive, assess lower with a photo, and the revised page with no pay; the customer confirm and return; accept with credit (balance shown on the account); cash mark-paid.
  - Every browser agent gets its own port, DB copy and `APP_URL` (also on tinker that mints links), and waits on a PID or a run-unique marker.
  - `reviewer` once per step, with both the code review and the design review (`design-review` skill) in one call.
- **Fix the findings,** then commit per step: `feat(credit): …`, `feat(buylist): …`, `feat(buylist): …`. A bug in scope gets its own `bug` issue.
- **Side task:** send mechanism-level field notes to the agent-setup session at `uds:/tmp/cc-socks/28648.sock` on the four untested rules (waits, base URL, capture failures, wedged browser).
- **M9 is done when** all M9 issues are closed and, on a seeded local app:
  - a customer spends credit at checkout;
  - a customer builds a list on `/buylist` and submits it, then receives a `BUY-` number and a quote date;
  - staff receive it and assess one card lower with a photo;
  - the customer confirms the new total;
  - credit lands on the account and the cards are in stock.
