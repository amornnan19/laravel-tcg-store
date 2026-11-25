# M5 · Checkout and payment

> **สรุป (ไทย):** แบ่งเป็น 3 ขั้น ทำทีละขั้น
> **A — Checkout และคำสั่งซื้อ:** หน้า `/checkout` มีช่องกรอกข้อมูลผู้รับและที่อยู่ เลือกขนส่ง 4 เจ้า (เรทอยู่ใน config) แพ็กกันการ์ด และวิธีจ่าย
> กด "ยืนยันคำสั่งซื้อ" แล้ว `PlaceOrder` จะตัดสต็อกจริงและสร้าง order, order_items (เก็บ snapshot), payment ใน transaction เดียว
> หน้าคำสั่งซื้อ `/orders/{number}` เปิดได้ผ่าน signed URL ถ้าไม่จ่ายภายใน 30 นาทีคำสั่งซื้อจะถูกยกเลิกเองและคืนสต็อก
> **B — การจ่ายเงิน:** สร้าง PromptPay QR (EMVCo) ในเครื่อง มีตัวนับถอยหลังและปุ่ม "สร้าง QR ใหม่" มีข้อมูลบัญชีสำหรับโอนธนาคาร
> อัปโหลดสลิป สถานะ "กำลังตรวจสลิป" ที่ poll อัปเดต และกรณียอดไม่ครบจะมีปุ่ม "โอนส่วนที่ขาด" / "แนบสลิปใหม่"
> **C — ฝั่ง admin:** หน้า Orders แบบโต๊ะทำงาน (สถานะ, ไทม์ไลน์, ปุ่มยืนยันหรือปฏิเสธสลิป, รายการของ, ที่อยู่, เลขพัสดุ) และหน้า payment desk
> ปุ่มเปลี่ยนสถานะ (แพ็ก, ส่ง, ส่งถึง, ยกเลิก, คืนเงิน) แจ้งลูกค้าทางอีเมล และมี widget 2 ตัว
> **ไม่ทำในรอบนี้:** บัตรเครดิต (ต้องมี gateway และสัญญา merchant), เครดิตร้าน, ประกันของเกรด, LINE (M6)

## Context

M4 shipped the cart and 15-minute holds. M5 in `docs/README.md` is "addresses, couriers, protective packing,
PromptPay QR, slip upload, the admin's payment desk". Behaviour: `docs/payments-shipping.md` (PromptPay, slip
upload, shipping, protective packing, order status), `docs/data-model.md` (`orders`, `order_items`, `payments`),
`docs/admin.md` (Orders desk, payment desk, widgets), `docs/architecture.md` (PlaceOrder, the tests to write
first). Design: `storage/canvas-ref/project/MCheckout.dc.html` (whole artboard), `States.dc.html` ("สลิปไม่ผ่าน",
"QR หมดอายุ"). The user delegated every M5 decision to the orchestrator; they are below.

## Rules in force

`.ai/rules/project.md`, every rule; especially: money is integer satang, formatted only by `<x-price>` /
`Money`; **orders snapshot what was bought**; **never print an invented price, address, phone or hour — the
shop supplies them** (config/env only, never Blade); the reservation rules (lock the variant first, one upsert,
one purchasability check); Thai only in `lang/th/`; enums in `app/Enums`; Livewire `unset()` rule, `#[Locked]`;
`aria-disabled`; `focus-visible`; Tailwind same-property colour rule; Filament: policies on every resource, IBM
Plex Sans Thai, a header action that changes related rows saves first; the panel's 2FA rule (nothing here logs
anyone in). `docs/design-tokens.md` only. Boost `search-docs` before Livewire 4 uploads/polling, Filament 5
pages/actions/infolists/widgets, notifications, signed URLs, scheduler. Skills `livewire-development`,
`tailwindcss-development`, `laravel-best-practices`, `testing-best-practices`. Pint. Implementer does not commit.

## Decisions (do not reopen)

### Scope

- **Guest checkout.** Accounts are M6. `orders.user_id` is a nullable fk to `users` (M6 fills it). Contact is
  on the order: recipient name, phone, email.
- **Payment methods offered: PromptPay QR and bank transfer + slip.** No card (needs a gateway and a merchant
  agreement; not in M5's scope line). No store credit, no graded insurance: `credit_used_satang` and
  `insurance_satang` exist and are 0.
- **Notifications: email** (Laravel mail notifications to the order's email, routed with
  `Notification::route('mail', …)`; `MAIL_MAILER=log` locally). LINE arrives in M6.
- **The design's promises that are not true yet are not printed:** no "ยืนยันอัตโนมัติใน 1 นาที", no
  "ภายใน 30 นาที", no "ส่งพรุ่งนี้ถ้าจ่ายก่อน 15:00", no "ระบบอ่านยอดและเวลาให้" (no slip OCR).

### Shop-supplied data (config + env, never Blade)

- `config/shipping.php`: `couriers` keyed by the `Courier` enum value (`flash`, `kerry`, `jt`, `thaipost`), each
  `rate_satang` (env-overridable, defaults 4000 / 5000 / 4000 / 3500 as the artboard), `days_th` label key,
  `tracking_url` template with `{number}` (the couriers' public tracking pages:
  Flash `https://www.flashexpress.co.th/fle/tracking?se={number}`, Kerry
  `https://th.kerryexpress.com/th/track/?track={number}`, J&T `https://www.jtexpress.co.th/service/track?bills={number}`,
  Thailand Post `https://track.thailandpost.co.th/?trackNumber={number}`); `protection_satang` (3000) and
  `protection_threshold_satang` (50000).
- `config/shop.php` gains: `payment_window_minutes` (30), `qr_minutes` (15), `promptpay_id`
  (`SHOP_PROMPTPAY_ID`), `bank` (`SHOP_BANK_NAME`, `SHOP_BANK_ACCOUNT_NAME`, `SHOP_BANK_ACCOUNT_NUMBER`),
  `slip_max_kb` (5120). `.env.example` gets the keys, empty. **A method whose details are not configured is not
  offered**; if neither is, checkout shows `checkout.no_methods` and no confirm button. The local `.env` (not
  committed) gets demo values `SHOP_PROMPTPAY_ID=0000000000` and a placeholder bank `ธนาคารตัวอย่าง` /
  `ร้านตัวอย่าง` / `000-0-00000-0`, so the demo can never point a real person's account.

### Schema (Step A)

- `orders`: `id`, `number` (string unique), `user_id` (nullable fk null-on-delete), `status` (`OrderStatus`),
  `contact_name`, `contact_phone`, `contact_email`, `address` (json, the snapshot: recipient, phone, line1,
  subdistrict, district, province, postcode), `courier` (`Courier`), `subtotal_satang`, `shipping_satang`,
  `protection_satang`, `insurance_satang` (default 0), `credit_used_satang` (default 0), `total_satang`,
  `tracking_number` (nullable), `staff_note` (text nullable), `payment_due_at`, `placed_at`, `paid_at`,
  `packed_at`, `shipped_at`, `delivered_at`, `cancelled_at`, `refunded_at` (all timestamps nullable except
  `placed_at`, `payment_due_at`), `timestamps`. Index `(user_id, placed_at)` (docs), `(status, payment_due_at)`.
  `number` = `sprintf('TCG-%05d', $id)` set right after insert in `PlaceOrder`.
- `order_items`: `id`, `order_id` (fk cascade), `purchasable_type/id` (`nullableMorphs` — the variant may be
  deleted later), snapshot `card_name`, `set_code`, `number`, `language`, `condition`, `finish`,
  `unit_price_satang`, `qty`, `line_total_satang`, `timestamps`. Snapshot columns are plain strings (the enum
  values), so an order reads correctly whatever happens to the catalogue.
- `payments`: `id`, `order_id` (fk cascade), `method` (`PaymentMethod`: `promptpay`, `bank_transfer`, `card`),
  `status` (`PaymentStatus`: `pending`, `submitted`, `confirmed`, `rejected`, `refunded`), `amount_satang`,
  `qr_payload` (text nullable), `qr_expires_at`, `slip_path`, `slip_amount_satang`, `slip_ref`,
  `slip_paid_at`, `submitted_at`, `rejected_reason`, `gateway_ref` (all nullable), `shortfall_of_payment_id`
  (nullable fk to `payments` — the gap payment points at the short one), `confirmed_at`, `timestamps`.
- Enums `OrderStatus` (with `label()` from `lang/th/enums.php` and `color()` token), `PaymentMethod`,
  `PaymentStatus`, `Courier` (with `label()`, `rateSatang()` from config, `trackingUrl(string)`).
- Models `Order` (casts, `items`, `payments`, `latestPayment` = latest by id, `isPaid()` etc.), `OrderItem`,
  `Payment`; factories with states (`awaitingPayment`, `paid`, `submitted`, …); `$fillable`, not `#[Unguarded]`.
  A seeder `OrderSeeder` creates a few demo orders in different statuses from seeded variants; the
  open ones (awaiting payment, paid, packing) take their stock as PlaceOrder would (#28) so the admin desk has something to show; called from `DatabaseSeeder`
  after the catalogue. Slips in the seeder: none (no image files committed).

### Checkout (Step A)

- `/cart` gains the primary button "ไปชำระเงิน" (`cart.checkout`) under the summary, shown only when at least
  one line is live and no line is expired or gone (otherwise the existing excluded note plus
  `cart.checkout_blocked` "ล็อกของให้ครบหรือเอาออกก่อนชำระเงิน").
- `GET /checkout` → `CheckoutController` renders `<livewire:storefront.checkout-form />`; no cart or nothing live
  → redirect to `cart`. Layout follows MCheckout from "ที่อยู่จัดส่ง" down; the top shows the lock banner (reuse
  CartPanel's banner markup extracted into a Blade component `<x-lock-banner :expires-at>` used by both) and a
  compact read-only line list (name, meta, qty, `<x-price>`), with a "แก้ไขตะกร้า" link to `cart`.
- `CheckoutForm` fields (all validated in a Livewire form object `CheckoutData`): `name` (required, ≤100),
  `phone` (required, Thai phone: digits after stripping spaces/dashes, 9–10, starts with 0), `email` (required,
  email), `line1` (required ≤200), `subdistrict`, `district`, `province` (required ≤100 each, text inputs),
  `postcode` (required, 5 digits), `courier` (required, `Courier` enum, default `flash`), `protection` (bool,
  default true when any live line's unit price ≥ `protection_threshold_satang`, else false), `method`
  (required, one of the offered methods, default the first offered). Never use `wire:model.live` on text
  inputs that get normalised; normalise in the action. Courier and payment choices are radio groups styled as
  the artboard's cards (real `<input type="radio">` + label; `has-checked:` styles; focus-visible ring).
- Summary card "สรุปยอด": `ค่าสินค้า (N รายการ)`, `ค่าส่ง :courier`, `แพ็กกันการ์ด` (when on), total
  "ยอดที่ต้องชำระ". Sticky bottom bar (phone) / in place (desktop) "ยอดรวม" + "ยืนยันคำสั่งซื้อ". Under the
  summary: `checkout.terms` "เมื่อยืนยันแล้ว การ์ดจะถูกตัดออกจากสต็อกทันที และต้องชำระเงินภายใน :minutes นาที
  ไม่เช่นนั้นคำสั่งซื้อจะถูกยกเลิกและคืนของเข้าร้าน".
- `PlaceOrder::handle(Cart $cart, CheckoutData $data): Order` — one `DB::transaction`:
  1. Lock every variant of the cart's lines with `lockForUpdate()` **first**, ordered by id (one query
     `whereIn(...)->orderBy('id')->lockForUpdate()->get()`), before any other read (project rule).
  2. Re-read lines + reservations. Every line must have a live reservation whose qty equals the line qty and
     must pass the purchasability check; otherwise throw `App\Exceptions\CheckoutBlocked` (the form shows
     `checkout.blocked` and links to the cart). Also refuse an empty cart.
  3. Decrement each variant's `qty` by the line qty (never below 0 — if it would be, that is a bug: throw).
  4. Create the order (status `awaiting_payment`, `placed_at = now`, `payment_due_at = now +
     payment_window_minutes`), set `number`, create `order_items` snapshots from the variant/printing/card/set
     at this moment, prices = the line's held `unit_price_satang` (the price the customer saw), compute totals
     (`subtotal` + courier rate + protection), create the first `Payment` (`pending`, chosen method,
     `amount = total`; PromptPay gets its payload and `qr_expires_at` — Step B; in Step A the payload column
     stays null).
  5. Delete the cart's reservations and items (the reservation is consumed, not released).
  After commit: send `OrderPlaced` notification (signed link). Return the order.
- After `PlaceOrder` the form redirects to the signed order URL and stores the order id in the session list
  `orders` (so the visitor's own session can open it unsigned later — see access below).
- `CancelOrder::handle(Order $order, string $reason)`: allowed from `awaiting_payment` and `paid`; locks the
  order row and the variants first; returns each item's qty to its variant when the variant still exists
  (`increment`); payment `pending|submitted` → `rejected` with the reason; status `cancelled`, `cancelled_at`;
  notify `OrderCancelled`. **Test that cancelling returns the quantity** (docs).
- `App\Jobs\CancelUnpaidOrders` (queued, scheduled every minute in `routes/console.php`): orders
  `awaiting_payment` with `payment_due_at <= now` and no payment in `submitted` → `CancelOrder` with
  `orders.cancel_unpaid` "ไม่ได้ชำระเงินภายในเวลาที่กำหนด". An order with a submitted slip waits for staff.

### Order page (Step A shell, Step B payment)

- `GET /orders/{order:number}` → `OrderController`, name `order.show`. Access: a valid signature
  (`URL::signedRoute`, no expiry) **or** the order id in the session's `orders` list; otherwise 404 (not 403 — do
  not confirm the number exists). The signed link is what goes in every email.
- Page: order number, status badge (`OrderStatus` colour), a timeline from the timestamps only (placed → paid →
  packing → shipped → delivered, or → cancelled / refunded), the items snapshot, address, courier + tracking link
  when shipped (`Courier::trackingUrl`), totals. While `awaiting_payment`, `<livewire:storefront.payment-panel
  :order-id>` (Step B; Step A renders the amount and "รอชำระเงิน" only).

### Payment (Step B)

- `App\Support\PromptPay`: `payload(string $promptPayId, int $amountSatang): string` — EMVCo merchant-presented
  QR: `000201`, `010212` (dynamic, amount present), tag 29 with AID `A000000677010111` and sub-tag `01`
  (mobile: `0066` + number without leading 0, 13 chars) or `02` (13-digit tax/national id) or `03`
  (15-digit e-wallet), `5303764`, `54` amount with two decimals, `5802TH`, `6304` + CRC16-CCITT (poly 0x1021,
  init 0xFFFF) uppercase hex. Unit tests against known-good payloads (compute two by hand in the test from the
  spec, e.g. id `0812345678` amount ฿100.00 and a 13-digit id) and CRC of `123456789` = `29B1`.
- Rendering: `chillerlan/php-qrcode` is already installed (through Filament's 2FA); **declare it explicitly in
  `composer.json` `require` at the installed `^5.0`** so the storefront does not depend on Filament for it — no
  new package enters `composer.lock`. `<x-qr-block :payload :amount :expires-at>` renders the SVG inline
  (`role="img"`, `aria-label` "QR PromptPay ยอด :amount").
- `PaymentPanel` (Livewire, `#[Locked] orderId`; resolves the order and re-checks access every request):
  - **PromptPay pending:** the QR block per MCheckout: QR, "PromptPay", amount with satang ("฿ 19,350.00"
    through `Money`), shop name (`config('app.name')`) · the PromptPay id formatted, countdown "QR หมดอายุใน
    mm:ss" (same Alpine countdown pattern as the lock banner; on 0 → `$refresh`), buttons "คัดลอกยอด" (Alpine
    clipboard, copies the plain number "19350.00") and "บันทึก QR" (a signed route returning a PNG of the same
    payload, `Content-Disposition: attachment`). Expired QR within the payment window → the States card "QR
    หมดอายุแล้ว · คำสั่งซื้อยังอยู่ และของยังถูกล็อกไว้อีก :minutes นาที" + "สร้าง QR ใหม่"
    (`RefreshPaymentQr`: same payload, new `qr_expires_at = min(now + qr_minutes, payment_due_at)`). Past the
    window: the order is (or is about to be) cancelled — show `payment.window_closed`.
  - **Bank transfer pending:** the bank name, account name, account number (copy button), amount.
  - Both: the slip upload button per the artboard ("แนบสลิปโอนเงิน", sub-line `payment.slip_hint` "ถ่ายรูปหรือ
    เลือกจากคลัง · jpg, png หรือ heic ไม่เกิน 5 MB"), Livewire `WithFileUploads`, validation `file|mimes:jpg,
    jpeg,png,heic|max:5120`, then `SubmitSlip::handle(Payment, UploadedFile)`: stores on the `local` (private)
    disk under `slips/{order number}/`, sets `slip_path`, `status = submitted`, `submitted_at`. Only a
    `pending` or `rejected`-then-new payment accepts a slip.
  - **Submitted:** "กำลังตรวจสลิป" + `payment.checking` "ร้านตรวจสลิปในเวลาทำการ เมื่อตรวจแล้วจะแจ้งทางอีเมล"; the
    component polls (`wire:poll.15s`) **only** in this state (docs/storefront.md performance rule).
  - **Rejected:** the States card: heading = the reason's first line, the reason verbatim
    (`rejected_reason`, e.g. "ยอดในสลิปไม่ตรง · สลิปโอน ฿ 19,300 · ยอดที่ต้องชำระ ฿ 19,350 (ขาด ฿ 50)"); when
    `slip_amount_satang` is set and short, two buttons "โอนส่วนที่ขาด" (`PayShortfall`: a new pending payment,
    same method, `amount = gap`, `shortfall_of_payment_id` = the rejected one, QR for the gap) and "แนบสลิปใหม่"
    (`RetryPayment`: a new pending payment for the full amount, same method); otherwise only "แนบสลิปใหม่".
  - **Paid:** "ชำระเงินแล้ว" + `paid_at` in Thai date/time.
- The method subtitles: PromptPay `checkout.method_promptpay_hint` "สแกนจ่ายแล้วแนบสลิป · ร้านตรวจในเวลาทำการ";
  bank `checkout.method_bank_hint` "โอนแล้วแนบสลิป · ร้านตรวจในเวลาทำการ".

### Admin (Step C)

- Actions in `app/Actions` (each one transaction, locks first, stamps its timestamp, notifies):
  `ConfirmPayment(Payment)`: `submitted` → `confirmed`, `confirmed_at`; the order becomes `paid` (`paid_at`) when
  `Order::receivedSatang()` (confirmed amounts + short-rejected slip amounts, #32) plus this payment reaches the total;
  `RejectPayment(Payment, string $reason, ?int $slipAmountSatang)`: `submitted` → `rejected`, stores both; the
  order stays `awaiting_payment` and its `payment_due_at` is extended to `now + payment_window_minutes` so
  the customer has time to act; `PackOrder` (`paid` → `packing`, `packed_at`); `ShipOrder(Order, string
  $tracking)` (`packing` → `shipped`, tracking, `shipped_at`, notify with tracking link — docs: one action, four
  effects); `DeliverOrder` (`shipped` → `delivered`); `CancelOrder` (Step A); `RefundOrder(Order, string $reason)`
  (`paid|packing` → `refunded`, confirmed payments → `refunded`, returns qty like CancelOrder, `refunded_at`).
  Illegal transitions throw `App\Exceptions\IllegalOrderTransition`; Filament only shows the legal button.
  **Money-in contracts from Step B (#32):** money received is `Order::receivedSatang()`. RejectPayment's reason is picked
  from fixed reasons — `amount_mismatch` (requires the slip amount; the stored reason is two lines: heading
  "ยอดในสลิปไม่ตรง" then "สลิปโอน :slip · ยอดที่ต้องชำระ :due (ขาด :gap)"), `unreadable` ("สลิปไม่ชัด อ่านยอดหรือเวลาไม่ได้"),
  `wrong_account` ("โอนเข้าบัญชีอื่นที่ไม่ใช่ของร้าน"), `other` (free text required); `slip_ref` is entered by staff on
  confirm/short-reject and a ref already credited on another payment of the same order is refused; RefundOrder refunds
  `receivedSatang()` (and shows it), and cancelling an order with `receivedSatang() > 0` shows staff the amount to return.
  **The test the docs ask for:** a short slip moves the payment to `rejected` with a reason, never `confirmed`.
- `OrderResource` (navigation group "คำสั่งซื้อ", Thai labels), list: number, placed, contact name, total
  `<x-price>`-equivalent money column, status badge, payment status; filters by status; default sort newest.
  View page (no create; edit only `tracking_number` and `staff_note`), sections in the docs' order: (1) status
  + timeline + the one next-step action button; (2) payment: method, amount, slip image full size (or a
  download link for HEIC), **ยืนยัน** / **ปฏิเสธพร้อมเหตุผล** — the reject modal has `slip_amount` (MoneyInput,
  optional) and `reason`; when `slip_amount` is filled and short, `reason` is prefilled live with
  `admin.payment.short_reason` "ยอดในสลิปไม่ตรง · สลิปโอน :slip · ยอดที่ต้องชำระ :due (ขาด :gap)"; (3) items with
  link to the variant edit page when it still exists; no storage locations exist, so the pick list is sorted
  by set code then number; (4) address + courier + tracking field (ship action asks for it); (5) staff note.
  Cancel and refund actions with a required reason, `requiresConfirmation`.
- Slip image: route `GET /admin/slips/{payment}` (name `admin.slip`) behind `auth` + `is_staff` (reuse the
  panel's staff check / `PaymentPolicy::view`), streams from the private disk. Never a public URL.
- `PaymentDesk` Filament page ("ตรวจสลิป"): every `submitted` payment oldest first, each a card with the slip
  beside order number, customer, amount due (and, for a shortfall payment, the earlier short slip and its
  amount) and the two buttons (same actions as the view page). No search.
- Widgets (replace `FilamentInfoWidget`; keep `AccountWidget`): "สลิปรอตรวจ" (count of submitted, links to the
  desk) and "ออเดอร์รอแพ็กวันนี้" (count of `paid`, links to the filtered list). The other two docs widgets are
  for later milestones.
- Policies: `OrderPolicy`, `PaymentPolicy` (staff only, like the existing ones). Notifications in
  `app/Notifications`: `OrderPlaced`, `PaymentConfirmed`, `PaymentRejected` (the reason verbatim),
  `OrderShipped` (tracking link), `OrderCancelled`, `OrderRefunded` — Thai subject/lines from
  `lang/th/notifications.php`, each with the signed order link; queued.

## Copy

All customer strings in `lang/th/storefront.php` under `checkout.*`, `order.*`, `payment.*` (plus
`cart.checkout`, `cart.checkout_blocked`); admin strings in `lang/th/admin.php`; enum labels in
`lang/th/enums.php`; notifications in `lang/th/notifications.php`. Use the artboard's wording where it exists
(ที่อยู่จัดส่ง, ขนส่ง, แพ็กกันการ์ด + "ซองใส + กระดาษแข็งประกบ + ซองกันน้ำ · แนะนำสำหรับการ์ดมูลค่าเกิน :threshold",
วิธีชำระเงิน, PromptPay QR, โอนธนาคาร + แนบสลิป, สรุปยอด, ค่าสินค้า (:n รายการ), ค่าส่ง :courier, แพ็กกันการ์ด,
ยอดที่ต้องชำระ, ยอดรวม, ยืนยันคำสั่งซื้อ, QR หมดอายุใน, คัดลอกยอด, บันทึก QR, แนบสลิปโอนเงิน, QR หมดอายุแล้ว, สร้าง QR
ใหม่, ยอดในสลิปไม่ตรง, โอนส่วนที่ขาด, แนบสลิปใหม่). Write the rest plainly; never promise a time the shop has not
set. Courier day labels: Flash/Kerry "1–2 วัน", J&T "2 วัน", ไปรษณีย์ไทย "2–3 วัน" (docs table). Order status
labels: รอชำระเงิน, ชำระเงินแล้ว, กำลังแพ็ก, จัดส่งแล้ว, ส่งถึงแล้ว, ยกเลิกแล้ว, คืนเงินแล้ว.

## Step A — checkout, PlaceOrder, order page shell, auto-cancel (#25)

Config, enums, migrations, models, factories, OrderSeeder, `<x-lock-banner>`, cart checkout button,
CheckoutController/CheckoutForm/CheckoutData, PlaceOrder, CancelOrder, CancelUnpaidOrders + schedule,
OrderController + access rule, order page shell, OrderPlaced/OrderCancelled notifications.
Tests: PlaceOrder decrements qty, consumes (deletes) reservations, empties the cart, snapshots items (an order
keeps its names and prices after the card is renamed and the variant re-priced/deleted — docs test), totals with
and without protection per courier; refuses an expired line, a gone line, an empty cart (nothing written);
lock-first query order (the DB::listen pattern from CartReservationTest); checkout validation (phone, postcode,
an unoffered method); protection default rule; a method without config is not offered; order page: signed URL
ok, session-owned ok, neither → 404; CancelUnpaidOrders cancels only overdue orders without a submitted slip and
**returns the quantity**; notifications sent (Notification::fake) with the signed link.

## Step B — PromptPay, payment panel, slip upload (#26)

PromptPay support + unit tests, `chillerlan/php-qrcode` declared, `<x-qr-block>`, the QR PNG route, PaymentPanel
(all states), SubmitSlip, RefreshPaymentQr, PayShortfall, RetryPayment, PlaceOrder now fills the payload.
Tests: payload + CRC vectors; QR expiry and refresh never exceed `payment_due_at`; slip validation (type, size),
stored privately, status → submitted, poll only while submitted; rejected short → both buttons, shortfall
payment amount = gap and points at the short one; access re-check (a tampered orderId 404s).

## Step C — admin desk, payment desk, status actions, widgets (#27)

Actions, OrderResource + view page, PaymentDesk page, slip route, widgets, policies, the remaining
notifications. Tests: every transition legal/illegal; short slip → rejected with prefilled reason (docs test);
ConfirmPayment on a shortfall payment marks the order paid; RejectPayment extends `payment_due_at`; ShipOrder
writes tracking + status + `shipped_at` + notification in one transaction; RefundOrder returns qty; slip route
403/404 for non-staff and guests; the desk lists only submitted, oldest first; Filament pages render
(`livewire(...)->assertOk()`) and actions run (`callAction`), per the existing admin test patterns.

## Out of scope

Card payments and webhooks; store credit; graded insurance and graded/sealed purchasables (M8); pre-orders (M8);
LINE notifications, accounts, saved addresses (M6); slip OCR; shipments resource; storage locations; the other
two dashboard widgets.

## Order and done

A → B → C, one implementer run each. After each: `verifier` (full `php artisan test --compact`, pint,
`npm run build`, `migrate:fresh --seed`, browser run at 1440×900 and 390×844 with screenshots into
`storage/screens/m5-<step>/`: A = cart with checkout button, checkout form (empty, validation errors, filled),
order page after placing; B = QR, expired QR, bank transfer, slip uploaded/checking, rejected short; C = admin
order view, reject modal with prefilled reason, payment desk, widgets), `reviewer` (money, stock and public
endpoints are all touched), and `designer` for A and B. Fix findings, commit to `main` per step
(`feat(checkout): …`, `feat(payment): …`, `feat(admin): …`), closing its issue in milestone "M5 · Checkout and
payment". Bugs in M5's scope get their own `bug` issue in the milestone, fixed before the step's commit. M5 is
done when the three issues are closed and, on a seeded local app, a visitor can go cart → checkout → place the
order → see the QR → upload a slip, and staff can reject it short (customer sees the gap and pays it) or confirm
it, then pack and ship with a tracking number.
