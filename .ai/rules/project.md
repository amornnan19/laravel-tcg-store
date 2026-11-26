---
paths: ["**/*"]
description: Stack decisions, money and language rules, and how to use Laravel Boost in this project
---

# TCG store — project rules

Read this before planning or editing anything. It holds the decisions that are already made, so no
session has to make them twice.

## Use Laravel Boost

Boost is installed and its MCP server is registered in `.mcp.json`. Use it, every session:

- `search-docs` **before** writing code that touches a Laravel-ecosystem API — Laravel 13, Livewire
  4, Filament 5, Scout 11 and Socialite all moved recently, and training data is behind. Scope it
  with the `packages` array.
- `database-schema` before writing a migration or a model, instead of guessing at the current
  tables.
- `database-query` for read-only questions about the data, instead of `tinker`.
- `get-absolute-url` before giving the user any URL.
- `browser-logs` when something fails in the browser.
- `tinker` only for things a test cannot cover.

If a Boost tool and a shell command can both do the job, use the Boost tool.

Boost also owns `CLAUDE.md` and `AGENTS.md`. Never hand-edit the `<laravel-boost-guidelines>` block
in either: `php artisan boost:update` rewrites it. Project rules go in this directory instead, and
`php artisan boost:index-rules` regenerates `.ai/rules/index.md` after you add a file here.

## What this project is

A Thai trading-card shop, built as one Laravel app: storefront and admin in the same codebase.

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.5 — installed |
| Storefront | Blade + Tailwind 4 + Vite 8 — installed. **Livewire 4 is not installed yet**; it is step 0 of the build order in `docs/README.md` |
| Admin | Filament 5 at `/admin` — **not installed yet**, step 0 |
| Search | Laravel Scout + Meilisearch — **not installed yet**, step 0 |
| Auth | Laravel's own, plus LINE Login through Socialite — **not installed yet**, step 0 |
| Database | SQLite while building. Keep every migration portable; see `docs/data-model.md` |
| Tests | Pest 5 |
| Formatting | Pint, `vendor/bin/pint --dirty --format agent` before finishing |

Filament 5 and Livewire 4 share a runtime, which is why the storefront is Livewire and not Inertia:
one component model, one build, one set of conventions.

## The design is already decided

The screens, the flows and the design tokens exist as a design canvas —
<https://claude.ai/artifact/2NKx859ujDu9cA7n8UWLuE> — and `docs/` is written from it. The canvas is
the reference for layout, copy and states; the values are already transcribed into
`docs/design-tokens.md`, so never hard-code a hex, a size or a radius that is not in that file.

**Read the relevant file in `docs/` before building a feature** — the variant table, the
15-minute stock reservation, the deck-list buckets and the buylist re-inspection flow all have
behaviour specified there that is not obvious from the schema alone.

Do not invent screens. If a screen is missing from `docs/storefront.md`, ask.

## Rules that are easy to break

- **Catalogue models are `#[Unguarded]`.** Never pass `$request->all()` or any unvalidated array to
  `create`, `fill` or `update`; pass `$request->validated()` or Filament form state only.
- **Money is an integer of satang** in a column ending `_satang`. Never a float, never a decimal
  column, never a currency string in the database. Format at the edge only.
- **A variant is the sellable unit for singles** — one printing, one condition, one finish. Never
  collapse condition or language into the card or the printing.
- **Available stock is not `variants.qty`.** It is `qty` minus unexpired `stock_reservations`. Use
  the scope; never compute it inline.
- **Orders snapshot what was bought.** `order_items` and `orders.address` keep their own copies.
  Editing a card must never change an order that already exists.
- **Never print an invented price, address, phone number or opening hour.** The shop supplies them.
  Seeders and factories may use plausible values; Blade may not hard-code them.
- **A relation to a soft-deletable model (`Card`, `Printing`) that admin titles, labels or selects
  read must tolerate a trashed parent, and any has-children check before a hard delete must include
  trashed children:** foreign keys count them.
- **A search jump only targets what the index would show: never a printing of a trashed card or an inactive game, nor a set of an inactive game.**
- **The Filament panel uses `IBM Plex Sans Thai`; every relationship a repeater edits has an explicit
  order; a header action that changes related rows saves the form before refreshing it.**
- **A Filament Importer that overrides `resolveRecord()` or `fillRecord()` reads a column only when
  `filled($this->columnMap[$name] ?? null)`: `$this->data` still holds the raw CSV row keyed by header, so an
  unmapped column is present but was never validated. Every admin edit page that saves stock or price refuses a
  stale save.**
- **The admin panel's second factor is enforced only by Filament's login page.** Any other way of logging a user
  in (LINE/Socialite, a storefront login, password-reset auto-login, impersonation) must refuse `is_staff` users,
  or the panel must move to its own auth guard first. The panel shares the `web` guard today.
- **Search hits come back from Meilisearch without `searchVisible()`; hydrate them through `->query(fn ($b) => $b->searchVisible())` so a trashed card or inactive game never reaches a view before the queued re-index runs. Scout hydrates any SoftDeletes model with `withTrashed()`, so a `searchVisible()` on a soft-deleting model excludes `deleted_at` explicitly rather than relying on the global scope.**
- **Card art is placeholder only, rendered by `<x-card-art>`. Never fetch, store, hotlink or seed official card images from any game; staff-uploaded variant photos are the only real photos.**
- **A storefront count shown against `sets.card_count` counts distinct card numbers, never raw printings: printings include secret rares past the set size and every language.**
- **Focus rings use `:focus-visible` (`focus-visible:` / `has-focus-visible:`), never `focus-within:`, and never stack on an element whose border already turns accent.**
- **A Livewire action that changes state a `#[Computed]` property derives from must `unset()` every computed that depends on it; one request can carry several calls.**
- **A wrapper with `overflow-hidden` must not contain focusable controls (it clips the focus ring); a control at a limit uses `aria-disabled`, not `disabled`, so focus is not dropped.**
- **A property bound with `wire:model.live` is never rewritten server-side (trim, cap, case); normalise into a local variable, or the response overwrites what the user is typing. `wire:loading` on a grid or flex container needs the matching display modifier (`wire:loading.grid`).**
- **`trustProxies` trusts forwarded headers from any caller: the app container must be reachable only through the reverse proxy (no published port), and any URL emailed from inside a request (password reset) must be built from `APP_URL`, not the request host.**
- **A second app served on its own port needs `APP_URL` pointing at that port, set on the process that *mints* links, and on the server only by the means in the next bullet — a bare `APP_URL=…` before `artisan serve` is silently dropped.** `URL::signedRoute` (and anything else building a URL outside a request — `tinker`, a seeder, a queued job) has no request to take a root from, so it emits `APP_URL`. Measured 26 Nov: a review running on :8012 with its own database still sent its order-page GETs and a `POST /login` to another agent's server on :8000, because the step that minted its signed URLs ran in `tinker`. Use the exact host the browser will open — `127.0.0.1` and `localhost` are not interchangeable, and the mismatch just moves the breakage to session cookies and CSRF — and leave `SESSION_DOMAIN` unset.
- **`php artisan serve` drops environment overrides unless it is run with `--no-reload`.** With a `.env` present, `ServeCommand::startProcess()` blanks every variable outside `$passthroughVariables` for the PHP child, so `DB_DATABASE=copy.sqlite APP_URL=... php artisan serve` serves the real database and the `.env` URL (measured 26 Nov: a verifier's copy-backed server hit `database/database.sqlite`). A second app copy runs `php artisan serve --no-reload --port=N` with its overrides, and confirms through a page that the copy is the one being served.
- **A check that refuses an action because a row exists ("a slip is submitted") and runs under the order lock must itself be a locking read (`lockForUpdate()`): panel actions run inside an outer transaction, so a plain read sees the snapshot from before the lock while the following UPDATE sees the latest rows.**
- **In Livewire 4 `wire:model.blur` and `wire:model.change` only delay the client-side sync and send no request; server-side validation on blur needs `wire:model.live.blur`.**
- **A transaction that calls `ReserveStock` (or otherwise sums `stock_reservations` to decide a hold) must lock the variant with `lockForUpdate()` before any other read in that transaction: on MySQL's REPEATABLE READ an earlier plain read fixes the snapshot and the reservation sum misses holds committed while waiting on the lock.**
- **Every path that creates or increases a hold (`AddToCart`, `ChangeCartQuantity` up, `RenewCartItem`, swap, M5 `PlaceOrder`) applies the same purchasability check — price not null and `Printing::searchVisible()` — and the cart page treats a line that fails it as gone.**
- **Never combine a base colour utility and a conditional colour utility for the same property on one element (e.g. `text-ink` in a shared class string plus `'text-faint' => $atMin`): Tailwind v4 resolves same-property utilities by stylesheet order, not class order, so the conditional one can silently lose. Put every alternative colour in the conditional.**
- **On MySQL/MariaDB, never run a locking `DELETE`/`UPDATE` against a `stock_reservations` key that may not exist and then `INSERT` it in the same transaction: the miss takes a gap lock and two carts deadlock. Write a cart's hold with a single upsert on the unique key.**
- **A job or caller that filters orders before calling a shared action (CancelOrder, RefundOrder) must have that filter re-evaluated inside the action under the order's row lock; checking it before the call is a race. Seeders may create orders without taking stock only if those orders cannot reach an action that returns stock.**
- **A radio or checkbox styled as a card puts its focus-visible ring on the card (`has-focus-visible:outline-*`), never on the accent-bordered control inside it; text inside a card that turns `bg-raised` when selected uses `text-muted`, not `text-faint` (faint is 4.3:1 on raised).**
- **An action that stores an upload must check that the file exists before it writes the path to a row: on the `local` disk (`throw => false`) Livewire's `TemporaryUploadedFile::storeAs` returns the path even when the write failed.**
- **`lang/th/validation.php` applies to every form in the app, Filament admin included, and to every Livewire temporary upload. Keep its lines generic; wording for one field belongs in that component's `messages()`.**
- **Money received on an order is `Order::receivedSatang()`: confirmed amounts plus the slip amount of rejected payments that were short. Any action that pays out, refunds or cancels with money in must use it, and staff must not credit one slip (`slip_ref`) on two payments.**
- **A QR code, or anything that must stay light for a scanner, sits on a fixed white ground (`bg-white`), never on a theme token such as `bg-ink` or `bg-surface`: the light theme flips those.**
- **`bootstrap/app.php` sends every unauthenticated web request to the Filament panel login. Before any customer route uses `auth` (M6), make `redirectGuestsTo` choose by path, so customers never land on the staff login.**
- **Custom Blade views inside the Filament panel only get Tailwind utilities through the panel's custom theme (`resources/css/filament/admin/theme.css`); its `@source` must cover every folder those views live in.**
- **`session('orders')` lists orders this session placed or opened through a signed link: it is proof of access, never of ownership. Only `session('orders_placed')` (orders this session placed) may be claimed into an account.**
- **A screen has at most one gold (`bg-accent`) primary button; every other action on it uses the secondary button (`border border-line-strong bg-surface text-ink`).**
- **Promoting a user to staff must rotate their `remember_token` and end their sessions: a session made before `is_staff` was set reaches `/admin` without the second-factor challenge.**
- **Jobs and queued notifications get one attempt unless the class sets `$tries` (the worker default is `--tries=1`). A job that calls an external API sets `$tries` and `backoff()`, sends an idempotency key when the API supports one, and a customer message that cannot be delivered on its channel falls back to email rather than being dropped.**
- **A job that deletes `stock_reservations` keeps the expiry condition on the DELETE itself (`where('expires_at', '<=', $now)`), never only on an earlier SELECT: `ReserveStock` renews an expired row in place with the same id.**
- **A queued job that re-reads current state to act on it (`CheckVariantAlerts`) uses `ShouldBeUniqueUntilProcessing`, not `ShouldBeUnique`; and a queued notification snapshots what it says at the moment it is sent for, rather than re-reading models that may be trashed or changed before it runs.**
- **An action that belongs to one row (restock, price watch, remove) sits inside that row's bordered container, and its result or error renders next to that action, never in a region shared with another control.**
- **A unique re-read job is dispatched with `DB::afterCommit(fn () => Job::dispatch(...))`, not `->afterCommit()`: the latter takes the unique lock inside the open transaction, so a check that starts before the commit swallows the change.**
- **A result computed from user input (a parsed list, a quote) shows when the input has changed since it was computed, and any action on that result is `aria-disabled` until it is recomputed.**
- **A Meilisearch `_rankingScore` threshold is not a name match: a query with no word tokens scores 1.0 (placeholder search) and a one-word prefix scores about 0.98. Verify a fuzzy hit against the card's name before accepting it.**
- **Never fold Thai text with NFKC before comparing it with stored text: NFKC decomposes ำ (U+0E33). Use NFC, and fold only full-width ASCII.**
- **A Livewire `#[Computed]` is recomputed on every request. Expensive work derived from user input (a search, a parse) runs in the action the rate limit guards, and its result is kept in locked state or a cache, never re-derived in a computed that any later request re-runs.**
- **A guard that checks "what the customer saw is what we add" compares the full variant→quantity map (or a hash of it), never only a total; and pricing that sums several lines onto one variant allocates that variant's available stock across the lines, or the summed add can fail forever. Seeded users with a known password are created only when `app()->isLocal()`.**
- **A control that is soft-disabled with `aria-disabled` must look disabled and must answer a click: either hide it in the state where it does nothing, or show the reason next to it. An action that returns early without a notice is a dead button.**
- **A session value a guest leaves for "after login" is read with `pull()`, never `get()`, and only from the key `CustomerLogin` writes. Any other login (the Filament panel shares the web guard) leaves a guest's `put` value in place, and a `get()` replays it on every request.**
- **A one-of-a-kind item's system states (`reserved`, `sold`) are written only by the hold and order actions; admin forms offer only `listed`/`withdrawn`, and every status the purchasable scope accepts must satisfy the listing rule. A state change made inside a release or return path falls back (to `withdrawn`) and logs; it never throws, or one bad row strands every hold after it.**
- **Locking an admin status select on a system state does not protect the saving-hook rule: every field that rule reads must also be validated (or disabled) for each status the purchasable scope accepts, so a save surfaces as a form error, never a model exception.**
- **Shared line UI (`LinePresentation`, `x-line-art`, order item rows) renders each purchasable kind in its own shape: cards 63:88 via `x-card-art`, graded photos, sealed products square via `x-sealed-art`. An order snapshot's `detail` is customer-facing — never put a SKU in it.**
- **A `grid` that holds rows with `truncate` text sets `grid-cols-1` at the base breakpoint (or `min-w-0` on each item): an implicit grid track sizes to the text's full width and widens the page on phones. An autocomplete or listing row never shows a stock word for something the shop cannot sell (unpriced, pre-order): `searchVisible()` is not `purchasable()`.**
- **A transaction that takes a locking read or update on `payments` takes every other row lock it needs (pre-order, product) before that read: on MySQL/MariaDB the payments gap lock blocks a concurrent order's payment insert, and a later lock request deadlocks with it.**
- **A Livewire `render()` never passes view data under the name of a public property: Livewire 4 renders the property over it.**
- **Every Filament `DateTimePicker` sets `->timezone(config('shop.timezone'))`.**
- **A Filament page hook (`afterSave`, `afterCreate`, an action's `after`) runs inside the panel's save transaction, after the record's row is already written and locked: an action called from it takes its locks in the project-wide order relative to that row, or runs in `DB::afterCommit`.**
- **Every label next to a money figure says what the figure is: once store credit applies, a figure showing `payableSatang()` is labelled "ยอดที่ต้องโอน", never "ยอดรวม"; and a credit spend is guarded against the amount the customer saw.**
- **A Filament bulk action that saves records another action locks saves them in ascending key order (`sortBy` the key, or `lockForUpdate()->orderBy('id')` first): `getSelectedTableRecords()` applies the table sort, which is often `id desc`. A "shown quote" signature covers every field that decides what is bought (printing, condition, finish), not only prices and quantities.**
- **Every `ShopNotification` overrides `fallbackMailRoute()` with an address it snapshots itself: `failed()` runs with no notifiable, so the base route returns null and the message is dropped when LINE keeps failing. A Filament header action that saves the form first computes the figure in its confirmation from the form's live state, never from the saved record.**
- **A phone-only fixed bottom action bar never hides on input focus, and its page sets `scroll-padding-bottom` on `html` (as `#deck-add-bar` and `#checkout-bar` do in `resources/css/app.css`) so a focused field is never under it.**
- **Seeders that run in production (`db:seed --force` in the image) may use `fake()` and factories only because `fakerphp/faker` is in `require`; every demo seeder guards itself with `shouldSeedDemoData()`, is safe to run twice, and seeds nothing a scheduled job would act on or mail outside `isLocal()`.**

## Language

- Everything a customer reads is **Thai**. Card names stay in their own language (English, Japanese
  or Thai, from `printings.language`).
- Everything written into this repository is **English**: identifiers, file and column names, commit
  messages, code comments, `docs/`, and these rules.
- Translations live in `lang/th/`, not in Blade string literals.

## Conventions

- `php artisan make:` for every new file; `--no-interaction` always.
- Enums are PHP backed enums in `app/Enums`, cast on the model. Never a bare string in a query.
- One Livewire component per screen section, named after the section in `docs/storefront.md`.
- Feature tests over unit tests; a new table gets a factory and a seeder in the same change.
- Do not add a Composer or npm dependency without asking first.

## Branching

**Deployment status: not deployed.**

While that line says "not deployed", commit straight to `main`. No feature branch, no pull request.
Nobody is using the shop, there is nothing to break, and a branch per change at this stage is
ceremony that buys nothing.

**The first deploy ends that.** From then on `main` is what is running in production, so a commit
straight to it is a release nobody reviewed. Every change goes on its own branch —
`feat/<thing>`, `fix/<thing>`, `docs/<thing>`, `chore/<thing>` — and `main` only moves by merging
one in.

Whoever deploys first edits the line above to read **"Deployment status: deployed since
YYYY-MM-DD."** That line is the switch, and it is the only thing to check. Do not infer the answer
from the git history, from whether a remote exists, or from anything in `.env`.

## Commits

Conventional Commits, English, imperative, lower case after the colon, under 72 characters:
`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`, `perf:`, `style:`. Scope in parentheses
where it narrows usefully, such as `feat(buylist): ...`.
