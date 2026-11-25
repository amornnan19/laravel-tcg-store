# M6 · Accounts

> **สรุป (ไทย):** แบ่งเป็น 3 ขั้น ทำทีละขั้น
> **A — บัญชีและการเข้าสู่ระบบ:** สมัครด้วยอีเมลและรหัสผ่าน, เข้าสู่ระบบ, ลืมรหัสผ่าน, ยืนยันอีเมล และ LINE Login (สร้างบัญชีใหม่ หรือผูกกับบัญชีที่มีอยู่ ผูกได้จากหน้าบัญชีด้วย)
> บัญชีพนักงานเข้าระบบหน้าร้านไม่ได้ทุกช่องทาง (ปิด #7) ผู้ใช้ที่ยังไม่ล็อกอินจะถูกพาไปหน้าล็อกอินของร้าน ไม่ใช่หน้า admin
> หน้า `/account` แสดงคำสั่งซื้อของฉัน เจ้าของบัญชีเปิดหน้าคำสั่งซื้อของตัวเองได้ คำสั่งซื้อที่ทำไว้ตอนยังไม่ล็อกอินใน browser เดียวกันจะถูกดึงเข้าบัญชี และ checkout กรอกชื่อกับอีเมลให้
> **B — แจ้งเตือนทาง LINE:** ทุกข้อความไปผ่าน `ShopNotification` ตัวเดียว ส่งทาง LINE ถ้าลูกค้าผูก LINE และเปิดรับไว้ ถ้าไม่ใช่ส่งอีเมล มีสวิตช์เปิดปิดในหน้าบัญชี
> **C — Wishlist และการแจ้งเตือน:** ปุ่มหัวใจบนหน้าการ์ด, ปุ่ม "แจ้งเมื่อของเข้า" ที่แถวที่หมด, "ติดตามราคา" พร้อมราคาเป้าหมาย
> ระบบส่งแจ้งเตือนครั้งเดียวแล้วปิดตัวเอง มีส่วนจัดการในหน้าบัญชี และ widget "แจ้งเตือนของเข้าที่ส่งไปเมื่อคืน" ฝั่ง admin
> **ไม่ทำในรอบนี้:** สมุดที่อยู่, เครดิตร้าน (M9), เด็คลิสต์ (M7), หน้า Users ใน admin

## Context

M5 shipped guest checkout and payment. M6 in `docs/README.md` is "LINE Login, order history, tracking, the wishlist,
alerts". Behaviour: `docs/accounts-line-buylist.md` (LINE Login, Notifications, Wishlist and alerts),
`docs/data-model.md` (`users`, `wishlist_items`, `price_alerts`, `restock_alerts`), `docs/storefront.md` (routes
`/account`, `/auth/line*`, `AlertToggle`, out-of-stock rows), `docs/admin.md` (access, the restock-alert widget).
Open issue #7 (staff MFA bypass once a second login exists) is in this milestone and is closed by Step A. The design
canvas has no account artboard locally; the account page is built from existing components and tokens
(`docs/design-tokens.md`), with copy from `States.dc.html` (empty wishlist) and `Sitemap.dc.html`
(เข้าสู่ระบบด้วย LINE, คำสั่งซื้อของฉัน). The user delegated every M6 decision; they are below.

Installed already: `laravel/socialite` 5.33 and `socialiteproviders/line` 4.1 (`LineExtendSocialite` listener in
`AppServiceProvider`), `config/services.php` `line.client_id|client_secret|redirect`.

## Rules in force

`.ai/rules/project.md`, every rule; especially: **the second-factor rule** (every non-panel login refuses
`is_staff`), **`redirectGuestsTo` must choose by path before any customer route uses `auth`**, **`trustProxies`:
any URL emailed from inside a request is built from `APP_URL`** (password reset, verification), Thai only in
`lang/th/`, `lang/th/validation.php` stays generic, enums in `app/Enums`, money in satang formatted only by
`<x-price>`/`Money`, a relation to a soft-deletable `Printing` tolerates a trashed parent, the purchasability check
(`price not null` + `Printing::searchVisible()`), available stock is the scope never inline, Livewire `unset()`
rule, `wire:model.live.blur` for validate-on-blur, `aria-disabled`, `focus-visible`, Tailwind same-property colour
rule, card-styled radio/checkbox focus rule. Boost `search-docs` before Socialite (fake, scopes), Laravel auth
(password broker, `MustVerifyEmail`, `VerifyEmail::createUrlUsing`, `ResetPassword::createUrlUsing`,
`RateLimiter`), notifications (custom channels, `ShouldQueueAfterCommit`), Livewire 4 redirects, Filament 5
widgets. Skills `laravel-best-practices`, `livewire-development`, `tailwindcss-development`,
`testing-best-practices`. Pint. Implementer does not commit.

## Decisions (do not reopen)

- **Keep the shared `web` guard; refuse staff on every storefront login path** (option 2 of #7). Moving the panel
  to its own guard was rejected: it rewrites every admin test's `actingAs` and the slip route for no gain once no
  storefront path can log a staff user in. Every storefront login goes through one class,
  `App\Support\CustomerLogin`, and nothing else in the storefront calls `Auth::login`.
- **Email + password accounts exist for customers too** (docs: "LINE is the default, not the only way").
- **Email verification exists but never gates shopping.** It is what makes auto-linking safe: a LINE login links
  to an existing account by email **only when that account's `email_verified_at` is set** and it is not staff.
  Otherwise someone could register with a victim's address, wait, and inherit the victim's LINE login.
- **The storefront forgot-password ignores staff addresses** and returns the same "sent" response.
  Staff use the panel. A password reset never logs anyone in.
- **No saved addresses (`addresses` table) in M6.** The milestone line does not include them, and the project is a
  demo that follows the docs' milestone lines. Checkout only prefills the name and email from the account.
- **`AlertToggle` holds the wishlist heart only.** The restock bell and the price watch live in `VariantTable`
  because it owns the variant rows. One Livewire component per row was rejected because it re-renders too much.
- **Alerts fire from one job, `CheckVariantAlerts`, dispatched after commit** wherever available stock or price
  can change. That means the `Variant` model `updated` event when `qty` or `price_satang` is dirty, plus the
  query-builder paths that change availability without model events: the release job, `CancelOrder`,
  `RefundOrder`, `RemoveCartItem`, and `ChangeCartQuantity` downwards.
- **One alert of each kind per user per variant** (unique keys). Setting it again reactivates and updates it.
- **The `channel` column records the preference at creation, for display.** The message is routed by the
  user's preference at send time through `ShopNotification`.
- **Out of scope:** the admin Users and Store credit resources, the Alerts resource, `preferred_language`,
  `store_credit_satang`, saved deck lists, and the buylist.

---

## Step A — accounts, auth, LINE Login, account page (issue #36; closes #7)

### Schema
- Migration `update_users_for_accounts` (portable `->change()`):
  - `email` becomes nullable and stays unique.
  - `password` becomes nullable.
  - Add `line_user_id` (string, unique, nullable), `line_display_name` (string, nullable), `line_avatar_url`
    (string 500, nullable) and `line_notify_opt_in` (bool, default false).
- `User`:
  - Implements `MustVerifyEmail`.
  - Casts `line_notify_opt_in` to bool. `#[Fillable]` stays `name, email, password`; the `line_*` columns are set
    with `forceFill` in the actions.
  - Adds the `orders()` hasMany, `hasLine(): bool` and `hasPassword(): bool`.
  - Overrides `sendEmailVerificationNotification()` so nothing is sent when `email` is null.
- `UserFactory`: add the states `staff` (only if it is missing), `withLine()` and `unverified`.

### Guests and login
- `bootstrap/app.php`: `redirectGuestsTo(fn (Request $r) => $r->is('admin', 'admin/*') ? route('filament.admin.auth.login') : route('login'))`. Replace the comment above it.
- `App\Support\CustomerLogin::login(User $user, bool $remember = false): void`:
  1. Throws `App\Exceptions\StaffLoginRefused` if `is_staff`.
  2. Logs the user in with `Auth::guard('web')->login`.
  3. Regenerates the session.
  4. Claims the session's orders: `Order::whereKey(session('orders', []))->whereNull('user_id')->update(['user_id' => $user->id])`.

  Callers turn the exception into the generic failure message.
- Routes in `routes/web.php`:

  | Route | Middleware | Name |
  |---|---|---|
  | GET/POST `/login` | `guest` | `login` |
  | GET/POST `/register` | `guest` | `register` |
  | GET `/forgot-password` | `guest` | `password.request` |
  | POST `/forgot-password` | `guest` | `password.email` |
  | GET `/reset-password/{token}` | `guest` | `password.reset` |
  | POST `/reset-password` | `guest` | `password.update` |
  | POST `/logout` | `auth` | `logout` |
  | GET `/email/verify/{id}/{hash}` | `auth`, `signed:relative`, `throttle:6,1` | `verification.verify` |
  | POST `/email/verification-notification` | `auth`, `throttle:6,1` | `verification.send` |
  | GET `/auth/line` | none | `line.redirect` |
  | GET `/auth/line/callback` | none | `line.callback` |
  | POST `/account/line/unlink` | `auth` | `line.unlink` |
  | GET `/account` | `auth` | `account` |

  The `guest` middleware sends a signed-in visitor to `account`: `redirectUsersTo(fn () => route('account'))`.
- Controllers in `app/Http/Controllers/Auth/`: `LoginController`, `RegisterController`, `PasswordResetController`,
  `EmailVerificationController` and `LineController`. Plus `App\Http\Controllers\Storefront\AccountController`.
  Form requests: `LoginRequest`, `RegisterRequest` and `ResetPasswordRequest`.
- **Login.**
  - Rate limit: 5 attempts per `Str::lower(email).'|'.ip` per minute through `RateLimiter`. When it is hit, show
    `auth.throttle` with the seconds left.
  - Retrieve the user by email, then `Hash::check` against a non-null password.
  - On failure, or when the user is staff, show the same `auth.failed` and count a hit.
  - On success: `CustomerLogin::login`, then `redirect()->intended(route('account'))`. "Remember me" is a checkbox.
- **Register.**
  - Validation: `name` (required, ≤100), `email` (required, email, ≤255, unique users), `password` (required,
    confirmed, `Password::min(8)`).
  - Create the user, fire `Registered` so the verification mail goes out, `CustomerLogin::login`, then redirect
    to intended or `account`.
- **Forgot and reset.**
  - Forgot uses `Password::broker()->sendResetLink`, but skips staff emails. It always returns the same status,
    `passwords.sent_generic`: "ถ้ามีบัญชีที่ใช้อีเมลนี้ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว".
  - Reset uses `Password::reset`, refuses staff (same as an invalid token: `passwords.token`), sets the password,
    fires `PasswordReset`, then redirects to `login` with the status `passwords.reset`. No login.
- **URLs emailed from a request use `APP_URL`.** In `AppServiceProvider::boot`:
  - `ResetPassword::createUrlUsing(fn ($u, $t) => rtrim(config('app.url'), '/').route('password.reset', ['token' => $t, 'email' => $u->email], false))`.
  - `VerifyEmail::createUrlUsing`: `URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => …, 'hash' => sha1(email)], absolute: false)` prefixed with `APP_URL`.
  - `ResetPassword::toMailUsing` and `VerifyEmail::toMailUsing` give Thai `MailMessage`s from `lang/th/notifications.php`.
- **Verification.**
  - `verify` checks that `hash_equals(sha1($user->email), $hash)` and that the id matches the signed-in user.
    It marks the address verified and redirects to `account` with a status.
  - `send` resends the link for an unverified email.
  - The account page shows the "ยืนยันอีเมล" notice with a resend button while the address is unverified.
- **LINE Login.**
  - `LineController::redirect` returns 404 when `config('services.line.client_id')` is blank. Otherwise
    `Socialite::driver('line')->scopes(['profile', 'openid', 'email'])->redirect()`.
  - When the user is signed in, `redirect` first does `session()->put('line.intent', 'link')`; otherwise it
    forgets that key. The account page's "ผูกบัญชี LINE" button is a link to `line.redirect`.
  - `callback`: an `error` query parameter, an `InvalidStateException` or any exception from `user()` sends the
    visitor to `login` (or to `account` when linking) with `auth.line_failed`. Otherwise read the id, name, email
    (nullable) and avatar. Then:
    1. **Linking (signed in and the intent is `link`):**
       - A staff user is refused with `account.line_staff`.
       - An id already on another user is refused with `account.line_taken`.
       - Otherwise `forceFill` the `line_*` columns onto the current user and redirect to `account` with
         `account.line_linked`.
    2. **Found by `line_user_id`:** refresh the display name and avatar, then `CustomerLogin::login`. A staff
       user gets `auth.line_staff` on `login`.
    3. **The email matches a user** whose `email_verified_at` is set and who is not staff: link (set the
       `line_*` columns) and log in.
    4. **Otherwise create a user.** The name is the display name, or `LINE user` when it is empty. `email` is the
       LINE email when no user has it, else null. `email_verified_at` is null and the password is null. Then
       log in.

    After a login: `redirect()->intended(route('account'))`.
  - `unlink`:
    - Allowed only when `hasPassword()` and `email` is not null. Otherwise refuse with
      `account.line_unlink_needs_password`.
    - It clears every `line_*` column and sets `line_notify_opt_in` to false.
- **Pages** (Blade views on the `x-layouts.storefront` layout; plain forms with `@csrf`; inputs styled like
  `checkout-form.blade.php`'s fields; errors under each field with `aria-describedby`):
  - `auth/login.blade.php`. When LINE is configured, the primary LINE button comes first, then the
    "หรือ" divider. Below them: email, password, remember, submit, and the links to forgot and register.
  - `auth/register.blade.php`.
  - `auth/forgot-password.blade.php`.
  - `auth/reset-password.blade.php`.
  - `account.blade.php`.

  The LINE button uses the LINE green only if `docs/design-tokens.md` has it. Otherwise it is the accent primary
  button with a LINE glyph. Never a new hex.
- **Account page** (`AccountController`):
  - Heading with the name and the LINE status.
  - The verification notice, when it applies.
  - "คำสั่งซื้อของฉัน": `$user->orders()->latest('placed_at')->paginate(10)`. Each row has the number, the
    placed date (Thai date), `<x-order-status-badge>`, the total as `<x-price>` and a link to `order.show`.
    When there are none: `<x-empty-state>` with `account.orders_empty` and a link to `home`.
  - "บัญชี LINE": link, or "เชื่อมกับ LINE แล้ว · :name" plus the unlink button.
  - The logout button.
- **Navigation** (`components/layouts/storefront.blade.php`):
  - The bottom bar gets a fourth item after the cart: "บัญชี" linking to `account`, with `aria-current` on
    account routes, styled exactly like the home item.
  - The desktop header gets a link beside the cart link: "บัญชี" when signed in, "เข้าสู่ระบบ" otherwise.
- **Order access.**
  - `OrderAccess` gains `canView(Order $order): bool`, which is true when `ownedBySession($order)` or when
    `auth()->id() !== null && $order->user_id === auth()->id()`.
  - `OrderController`, `PaymentPanel::order()` and `PaymentQrController` use `canView`.
  - `OrderController` still pushes to the session list only for signed visits.
- **Checkout.**
  - `PlaceOrder::handle(Cart $cart, CheckoutData $data, ?User $user = null)` sets `user_id`.
  - `CheckoutForm::placeOrder` passes `auth()->user()`.
  - `CheckoutForm::mount` prefills `form.name` and `form.email` from the user when they are signed in.
- **Copy:**
  - `lang/th/auth.php`: `failed` "อีเมลหรือรหัสผ่านไม่ถูกต้อง", `throttle` "ลองหลายครั้งเกินไป รอ :seconds วินาทีแล้วลองใหม่",
    `line_failed` "เข้าสู่ระบบด้วย LINE ไม่สำเร็จ ลองใหม่อีกครั้ง", `line_staff` "บัญชีพนักงานเข้าสู่ระบบที่หน้าร้านไม่ได้".
  - `lang/th/passwords.php`: `reset`, `token`, `sent_generic`, `throttled`.
  - `storefront.auth.*`: titles, labels, "เข้าสู่ระบบด้วย LINE", "หรือ", "ลืมรหัสผ่าน", "สมัครสมาชิก",
    "มีบัญชีอยู่แล้ว", "จดจำฉันไว้".
  - `storefront.account.*`: "บัญชีของฉัน", "คำสั่งซื้อของฉัน", `orders_empty` "ยังไม่มีคำสั่งซื้อ",
    "บัญชี LINE", "ผูกบัญชี LINE", "เชื่อมกับ LINE แล้ว · :name", "ยกเลิกการเชื่อม", "ออกจากระบบ",
    "ยืนยันอีเมล" / "เราส่งลิงก์ยืนยันไปที่ :email แล้ว" / "ส่งอีกครั้ง", `line_linked`, `line_taken`
    "บัญชี LINE นี้ผูกกับบัญชีอื่นอยู่แล้ว", `line_staff`, `line_unlink_needs_password`
    "ตั้งรหัสผ่านและอีเมลก่อน จึงจะยกเลิกการเชื่อม LINE ได้".
  - `storefront.nav.account` "บัญชี", `storefront.nav.login` "เข้าสู่ระบบ".
- **Tests** (Pest feature):
  - **Guest redirects.** A guest on `/account` goes to `/login`; a guest on `/admin` goes to the panel login.
  - **Email login.**
    - Logs a customer in and redirects to the intended URL.
    - A wrong password, a staff user and a null-password user all get `auth.failed` and stay guests.
    - The sixth attempt is throttled.
  - **Register.** Creates the user, sends `VerifyEmail` (`Notification::fake`) with a URL that starts with
    `APP_URL` when the request host differs, and logs the user in.
  - **Verification link.** Verifies the address. A tampered hash gives 403.
  - **Forgot password.**
    - A customer's email gets a reset notification whose URL starts with `APP_URL`.
    - A staff email sends nothing, with the same response.
  - **Reset.** Changes the password and leaves the visitor a guest. Staff are refused.
  - **LINE** (`Socialite::fake('line', …)`):
    - Creates a new user.
    - Logs in an existing `line_user_id` and refreshes the cached name.
    - Links to a verified email match.
    - Does **not** link to an unverified match: it creates a new user with a null email.
    - Refuses staff in both the login and the link paths, and the visitor stays a guest.
    - The link flow from the account page, and refuses an id taken by another user.
    - Error callback goes to `auth.line_failed`; redirect returns 404 when unconfigured.
  - **Unlink.** Refused without a password; clears the columns and the opt-in when allowed.
  - **Claiming orders.** Session orders with a null `user_id` are claimed on login. An order that already belongs
    to another user is not claimed.
  - **Owner access.**
    - The owner opens their order unsigned, and so does `PaymentPanel`.
    - Another signed-in user gets 404.
  - **Checkout.** Sets `user_id` and prefills the name and email.
  - **Account page.** Lists only the user's own orders, newest first.
  - **The staff probe from #7:** after every storefront login path, a staff user cannot reach `/admin/variants`,
    because they were never logged in.

## Step B — one notification base, LINE delivery, opt-in (issue #37)

- **Config.**
  - `config/services.php` `line.messaging_token` (`LINE_MESSAGING_CHANNEL_TOKEN`), with a comment: the LINE Login
    channel and the Messaging API channel must be under the same LINE provider, or the user id from Login is not
    the id the Messaging API pushes to.
  - `config/shop.php` `line_oa_url` (`SHOP_LINE_OA_URL`, the add-friend link).
  - `.env.example` gets both, empty.
- **`App\Notifications\Channels\LineChannel`:**
  - `send(object $notifiable, ShopNotification $n)`: `$to = $notifiable->routeNotificationFor('line', $n)`;
    return when it is null.
  - Otherwise `Http::withToken(config('services.line.messaging_token'))->timeout(10)->post('https://api.line.me/v2/bot/message/push', ['to' => $to, 'messages' => [['type' => 'text', 'text' => $n->toLine($notifiable)]]])->throw()`.
  - Register it as the `line` channel with `Notification::extend('line', …)` in `AppServiceProvider`.
  - `User::routeNotificationForLine()` returns `line_user_id`.
- **`App\Notifications\ShopNotification`** (abstract, `extends Notification implements ShouldQueueAfterCommit`,
  `use Queueable`):
  - `via($notifiable)`:
    1. `['line']` when the notifiable is a `User` with `hasLine()`, `line_notify_opt_in` and a configured token.
    2. Otherwise `['mail']` when the notifiable has a mail route (a `User` with an email, or an
       `AnonymousNotifiable` with a mail route).
    3. Otherwise `[]`.
  - `abstract toLine(object $notifiable): string`. The message is short and ends with the link on its own line.
  - `User::prefersLine(): bool` holds the same check as step 1, for reuse.
- **`App\Support\OrderRecipient::notify(Order $order, ShopNotification $notification): void`** sends to
  `$order->user` when the order has a user and `$order->user->prefersLine()`. Otherwise it sends to
  `Notification::route('mail', $order->contact_email)`.
  - Replace all 8 `Notification::route('mail', …)->notify(...)` calls: `RefundOrder`, `ShipOrder`, `PlaceOrder`,
    `PackOrder`, `DeliverOrder`, `ConfirmPayment`, `CancelOrder` and `RejectPayment`.
  - The 8 notifications extend `ShopNotification`. Each keeps its `toMail` unchanged, drops its own
    `via`/`Queueable`/interface, and gains `toLine` from new keys `notifications.<name>.line_text`. Examples:
    - `order_placed`: "รับคำสั่งซื้อ :number แล้ว ยอด :total ชำระภายใน :time".
    - `payment_confirmed`: "ร้านได้รับเงินคำสั่งซื้อ :number แล้ว".
    - `payment_rejected`: "สลิปของคำสั่งซื้อ :number ไม่ผ่าน: :reason".
    - `order_shipped`: "คำสั่งซื้อ :number ส่งแล้ว :courier เลขพัสดุ :tracking".

    Each is followed by `\n` and the signed order URL; for shipped, also the courier tracking URL. The money goes
    through `Money`.
- **Opt-in.**
  - Route `POST /account/notifications` (`auth`, name `account.notifications`) → `AccountController@notifications`
    with a validated `line_notify_opt_in` boolean.
  - When the user has no LINE, or the token is not configured, it refuses with `account.line_required`.
  - The account page section "การแจ้งเตือน" has a checkbox "แจ้งเตือนทาง LINE" plus a save button. The hint reads
    "ต้องเพิ่มเพื่อนบัญชี LINE ของร้านก่อน จึงจะได้รับข้อความ", followed by the add-friend link when `line_oa_url`
    is set.
  - Without LINE or the token, the section says "ร้านแจ้งทางอีเมล :email", or `account.no_channel`
    "ผูกบัญชี LINE หรือใช้อีเมลเพื่อรับการแจ้งเตือน" when there is no email.
- **Tests:**
  - `via` returns line, mail or nothing as above.
  - `LineChannel` posts the right body with the bearer token (`Http::fake`, `Http::assertSent`).
  - A failed push throws, so the queue retries.
  - `OrderRecipient`: a guest order goes to the contact email; an opted-in user's order goes to LINE; a linked but
    not opted-in user's order goes to the contact email.
  - Each of the 8 `toLine` texts contains the order number and the signed URL.
  - The opt-in endpoint saves, and refuses without LINE.
  - The existing M5 notification tests keep passing. Update them where they asserted
    `Notification::route('mail')`; the assertion stays `assertSentOnDemand` for guests.

## Step C — wishlist, price and restock alerts (issue #38)

- **Enum** `App\Enums\AlertChannel` (`line`, `email`) with `label()`.
- **Migrations.**
  - `wishlist_items`: `id`, `user_id` (fk cascade), `printing_id` (fk cascade), `timestamps`,
    `unique(user_id, printing_id)`.
  - `price_alerts`: `id`, `user_id` (fk cascade), `variant_id` (fk cascade), `target_satang` (unsigned int),
    `channel`, `is_active` (bool, default true), `last_fired_at` (nullable), `timestamps`,
    `unique(user_id, variant_id)`, `index(variant_id, is_active)`.
  - `restock_alerts`: the same, without the target, with `notified_at` in place of `last_fired_at`.
- **Models** `WishlistItem`, `PriceAlert`, `RestockAlert` (`$fillable`, casts). `WishlistItem::printing()` uses
  `->withTrashed()`. `User` gets the `wishlistItems`, `priceAlerts` and `restockAlerts` relations. Factories. No
  seeder rows: alerts are user data. A test proves the factories.
- **Actions** in `app/Actions`. Each takes a `User`, and refuses through `App\Exceptions\AlertRefused($reason)`
  with a `message()`, like `PaymentRefused`.
  - `ToggleWishlist(User, Printing): bool` adds when missing, deletes when present, and returns the new state.
  - `SetPriceAlert(User, Variant, int $targetSatang)`:
    - Refuses `target <= 0` (`invalid_target`).
    - Refuses when the variant is not purchasable (`unavailable`).
    - Refuses when the variant is available and `price_satang <= target` (`price_reached`): "ราคาตอนนี้ :price
      ต่ำกว่าราคาที่ตั้งแล้ว".
    - Refuses when the user has no channel, i.e. not `prefersLine()` and no email (`no_channel`).
    - Otherwise upserts on `(user_id, variant_id)`: target, channel, `is_active = true`.
  - `RemovePriceAlert(User, PriceAlert)` deletes the user's own alert only.
  - `SetRestockAlerts(User, array<int> $variantIds): int`:
    - Takes the purchasable variants among the ids whose available qty is 0.
    - Upserts each as active with `notified_at` null and returns how many it set.
    - The same `no_channel` refusal applies.
  - `RemoveRestockAlert(User, RestockAlert)`.
- **Firing.**
  - `App\Jobs\CheckVariantAlerts(int $variantId)` (`ShouldQueue`, `ShouldBeUnique` on the id for 10 seconds):
    1. Load the variant with its printing. Return when it is missing or not purchasable, or when the available
       qty (the scope) is 0.
    2. **Restock:** for each active restock alert, run a conditional
       `update(['is_active' => false, 'notified_at' => now()])` on that id with `is_active = true`. Only when one
       row changed, notify the user with `RestockAlertFired($variant)`.
    3. **Price:** do the same for active price alerts with `target_satang >= price_satang`, setting
       `last_fired_at` and notifying `PriceAlertFired($variant, $target)`.

    Use `chunkById`.
  - `App\Support\VariantAlerts::dispatchFor(iterable $variantIds)` dispatches one job per distinct id with
    `->afterCommit()`. Callers:
    - `Variant::booted` `updated` when `qty` or `price_satang` was changed.
    - `ReleaseExpiredReservations`, for the released variant ids.
    - `CancelOrder` and `RefundOrder`, for the returned ids.
    - `RemoveCartItem`.
    - `ChangeCartQuantity`, when the quantity went down.
  - `RestockAlertFired` and `PriceAlertFired` extend `ShopNotification`. Mail and LINE both name the card, the set
    code and number, the language, the condition and finish labels, and the price through `Money`, then link to
    `card.show`. The text:
    - Restock: "มีของแล้ว: :card (:meta) ราคา :price".
    - Price: "ราคาลดแล้ว: :card (:meta) เหลือ :price ถึงราคาที่คุณตั้งไว้ :target".
- **Storefront.**
  - **`Storefront\AlertToggle`** (Livewire, `#[Locked] printingId`):
    - The wishlist heart in the card page header, where the design has it beside the name and share.
    - It is an `aria-pressed` button with a 44 px target and the label "เพิ่มในรายการที่อยากได้" /
      "เอาออกจากรายการที่อยากได้".
    - A guest who clicks gets `session()->put('url.intended', route('card.show', …))` and is redirected to
      `login`.
  - **`VariantTable`:**
    - An out-of-stock row shows "แจ้งเมื่อของเข้า" in place of the add button, calling `notifyRestock(int $id)`
      (`SetRestockAlerts` with one id). Once set, the row shows "ตั้งแจ้งเตือนแล้ว" and a "ยกเลิก" button.
    - The selected available row gets a secondary "ติดตามราคา" button. It reveals an inline form with a baht
      input `priceTarget` (`wire:model`, normalised in the action, never `.live`), a submit and a cancel. On
      success it shows "จะแจ้งเมื่อราคาถึง :target" with "ยกเลิก". Refusals show under the form.
    - Guests are redirected to login with the intended URL, as above.
    - `unset()` every computed a change touches. The alert state comes from one computed query per render:
      the user's active alert ids for the printing's variants.
  - **Account page sections** (Step C adds them to `account.blade.php`):
    - "รายการที่อยากได้": the wishlist printings that are still `searchVisible`, newest first, each with
      `<x-card-art>` small, the name, set code and number, `<x-price>` from-price or "หมด", a link to the card,
      and a remove form. Removal is `DELETE /account/wishlist/{wishlistItem}` (`auth`, the owner only, else 404).
    - The empty state uses the States copy: "ยังไม่มีการ์ดที่ติดตาม" · "กดหัวใจที่การ์ดใบไหนก็ได้
      แล้วตั้งราคาที่รับได้ เราจะเตือนทาง LINE" · "ค้นหาการ์ด" (→ `search`).
    - "การแจ้งเตือน": price alerts (card, variant meta, target, current price, "รอราคา" / "แจ้งแล้ว :date") and
      restock alerts ("รอของเข้า" / "แจ้งแล้ว :date"), each with remove. Removal is
      `DELETE /account/alerts/price/{priceAlert}` and `DELETE /account/alerts/restock/{restockAlert}` (owner
      only).
- **Admin widget** `RestockAlertsFiredWidget` (dashboard, the 4th docs widget): "แจ้งเตือนของเข้าที่ส่งเมื่อคืน".
  It counts `restock_alerts.notified_at >= now()->subDay()` and lists up to 5 variants by count (card, meta,
  count) with links to the printing edit page. `canView` is for staff only.
- **Tests:**
  - The toggle actions.
  - `SetPriceAlert` refusals (`price_reached`, `invalid_target`, `no_channel`, `unavailable`) and upsert
    reactivation.
  - `SetRestockAlerts` skips in-stock and unpurchasable variants and takes many ids.
  - `CheckVariantAlerts`:
    - Fires the restock alert once when qty goes 0 → 3 through a model save (`Queue::fake` then run the job;
      `Notification::fake`), then deactivates. A second run sends nothing.
    - Does not fire while every unit is held by a reservation.
    - Fires after `ReleaseExpiredReservations` frees the stock.
    - Fires after `CancelOrder` returns qty.
    - Fires the price alert when the price drops to the target. An out-of-stock variant at the target price
      waits, then fires on restock.
    - An unpurchasable variant never fires.
  - The dispatch happens after commit; the job is queued once per variant.
  - The notifications route through `ShopNotification`.
  - `AlertToggle`: a guest is redirected to login; a user toggles the heart.
  - `VariantTable`: notifyRestock and the price watch, with errors.
  - The account page lists and removes only the user's own items; someone else's id gives 404.
  - The widget renders for staff.

---

## Order and done

A → B → C, one implementer run each.

1. First open three `enhancement`-labelled issues in milestone "M6 · Accounts": "Accounts: email and LINE login,
   account page, order history", "Notifications through LINE with one notification base" and "Wishlist, price
   alerts and restock alerts". #7 is closed by A's commit.
2. After each step:
   - `verifier`: full `php artisan test --compact`, Pint, `npm run build`, `migrate:fresh --seed`, and a browser
     run at 1440×900 and 390×844 with screenshots into `storage/screens/m6-<step>/`:
     - A: login, register with validation errors, forgot, account with orders and the verification notice, and
       the bottom bar's account item. The LINE button is shown by setting a dummy `LINE_CHANNEL_ID` in the
       browser run only. A redirect to LINE is not followed.
     - B: the account notification section in its states.
     - C: the card page heart off and on, out-of-stock "แจ้งเมื่อของเข้า" set and unset, the price-watch form
       with its error and success, the account wishlist and alerts (empty and filled), and the admin widget.
   - `reviewer` on every step (auth, public endpoints, notifications and stock-triggered jobs).
   - `designer` for A and C.
3. Fix the findings, then commit to `main` per step: `feat(accounts): …`, `feat(notifications): …`,
   `feat(alerts): …`. Each commit closes its issue. A bug in M6's scope gets its own `bug` issue in the milestone,
   fixed before that step's commit.
4. M6 is done when every M6 issue is closed and, on a seeded local app, the following works:
   - A visitor registers and logs in.
   - A guest's order made in the same browser shows under "คำสั่งซื้อของฉัน" after login.
   - The owner opens it unsigned.
   - A staff account cannot log in on the storefront.
   - A customer hearts a card, sets a restock alert on an out-of-stock row and a price watch on an in-stock row.
     Restocking that variant in the admin sends the restock email once (`MAIL_MAILER=log`).
