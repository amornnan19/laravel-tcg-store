# M2 · Search

> **สรุป (ไทย):** 2 ขั้น ทำทีละขั้น
> **A** Meilisearch ผ่าน `compose.yaml`, index `printings` + `sets`, อัปเดต index อัตโนมัติเมื่อ variant/การ์ด/เซ็ตเปลี่ยน,
> ตัวแปลงคำค้น (`OP05-119` → ไปที่การ์ด, `OP-05` → ไปที่เซ็ต) อิงรหัสเซ็ตจริงในฐานข้อมูล, service ค้นหาพร้อม fallback เมื่อ Meilisearch ล่ม
> **B** layout หน้าร้าน + header ตาม canvas (มือถือ/เดสก์ท็อป) + ช่องค้นหา autocomplete จัดกลุ่ม การ์ด/เซ็ต + แถวทางลัด
> ลิงก์ไปหน้าการ์ด/หน้าเซ็ตลงทะเบียน route ไว้แล้วแต่ยังเป็น 404 จนถึง M3

## Context

M1 finished the catalogue (5 real sets, 927 printings, 1,652 variants) and the Thai admin. M2 makes
the catalogue findable: `docs/search.md` is the spec ("search is the product"). The user chose that
M2 also builds the storefront layout and header from the canvas so the SearchBox is real and usable;
M3 builds the home, results and card pages on top of this layout. Meilisearch runs locally through a
`compose.yaml` in the repo.

## Rules in force (read before each step)

`.ai/rules/project.md` (all rules — several were added during M1), `docs/search.md` (the spec),
`docs/storefront.md` (routes, component names, responsive), `docs/design-tokens.md` (tokens, type
rules, motion, accessibility), `docs/architecture.md` (Conventions: money via one `<x-price>`,
enums, scopes, translations). Boost `search-docs` (packages: laravel/scout, livewire, laravel) before
Scout or Livewire 4 APIs; skills `scout-development`, `livewire-development`,
`tailwindcss-development`, `laravel-best-practices`, `testing-best-practices`. No new Composer/npm
dependency. Pint before finishing. No commits by the agent.

Canvas reference (the user's own design, saved locally; read-only):
`storage/canvas-ref/project/` (gitignored; re-fetch from the canvas if missing)
— `MHome.dc.html` (mobile header + bottom nav), `DHome.dc.html` (desktop header),
`MSearchFilter.dc.html` (M02a autocomplete overlay — the source for the result rows),
`Components.dc.html`, `States.dc.html` (empty/error states). Values come from
`docs/design-tokens.md`, never hex from the artboards.

## Decisions (do not reopen)

- **`Variant::scopeAvailable()` is added now as `qty > 0`**, with a PHPDoc saying M4 subtracts
  unexpired reservations here. Every "available/in stock" computation uses this scope.
- **Index `printings`** (model `Printing`, `searchableAs() = 'printings'`), fields exactly as
  `docs/search.md` plus `available_qty` (sum of available qty, for "มี 6 / เหลือ 1 / หมด") and
  `released_on` (printing's, else its set's). `number_plain` = integer of the digits before any `/`
  (`"062"` → 62, `"215/197"` → 215); `null` when no digits. `traits` from `cards.traits`.
  `shouldBeSearchable()`: printing not trashed, card not trashed, game `is_active`.
  `makeAllSearchableUsing()` eager-loads `card`, `set.game`, `variants`; `toSearchableArray()`
  computes the derived fields from the loaded variants filtered like the scope (no per-printing
  queries during import).
- **Index `sets`** (model `CardSet`): `id, code, name_en, name_th, game, card_count`.
- **Index settings** in `config/scout.php` → `meilisearch.index-settings` exactly as
  `docs/search.md` (searchable, filterable, sortable, ranking rules incl. `in_stock:desc`,
  `from_satang:asc`); `sets`: searchable `code, name_en, name_th`, filterable `game`. Applied with
  `php artisan scout:sync-index-settings`.
- **Freshness:** model observers, dispatched after commit — `Variant` saved/deleted/restored →
  its printing `searchable()`; `Card` updated/deleted/restored → its printings `searchable()` (or
  `unsearchable()` when trashed); `CardSet` updated → itself and its printings; `Game` updated →
  its printings. `SCOUT_QUEUE=true` stays.
- **compose.yaml** at repo root: service `meilisearch`, image `getmeili/meilisearch` pinned to the
  newest `v1.x` tag that `meilisearch/meilisearch-php` 1.17 supports (check its README/compat;
  record the tag in the report), port `7700:7700`, `MEILI_ENV=development`, `MEILI_NO_ANALYTICS=true`,
  volume `./storage/meilisearch:/meili_data` (gitignored). Update `docs/search.md`'s dev command to
  `docker compose up -d meilisearch`.
- **QueryParser** (`app/Search/QueryParser.php`, `app/Search/ParsedQuery.php`, readonly, `kind`
  enum `App\Enums\QueryKind` = `Printing|Set|Text`). Input is NFKC-folded (full-width `ＯＰ０５－１１９`
  works), lower-cased and split into tokens on whitespace and dashes. A set code is the normalised
  (`[a-z0-9]`) join of one or more WHOLE leading tokens, matched against the real codes of sets of
  **active games** (`op` + `05`, or `op05`; a joined token like `op05119` is never split, so `sv1` and
  `sv10` never collide). The rest must be a card number: `119`, `en062`, or `en` `062`, with the region
  limited to en, jp, ja, de, fr, it, sp, pt, kr, ko, ae, th, tc, sc; a `N/M` fraction keeps `N`. Matching
  is on set + `number_plain`, all languages, only printings the index would show (card not trashed, game
  active). When several codes match, the one whose remaining tokens name an existing number wins
  (`Printing`); otherwise a `Set` jump to the longest matching code (also when the number does not
  exist). The same code in two games keeps every set (`ParsedQuery::$setIds`; a printing match searches
  all of them; at most 2 set jump rows). A bare `190/190` is `Text` with `numberPlain`; anything else
  `Text`. The normalised code map is cached per request (scoped binding). Parser tests are a table
  covering every game's code shape plus negatives and prefix-sharing codes.
- **CatalogueSearch** (`app/Search/CatalogueSearch.php`): `autocomplete(string $query): AutocompleteResult`
  returning `jump` (ParsedQuery when not Text), up to 6 printings, up to 2 sets, and `limited` bool.
  A jump ADDS a row and never replaces results: printings and sets always come from a text search of the
  whole raw query (no set filter); for a `Printing` match the matched printings (in stock first) come first,
  then the text hits, de-duplicated, max 6. Sealed products are M8 (no group yet). On any exception from
  the engine: `report()` it, fall back to a DB query (`whereLike` on card `name_en/name_ja/name_th`, set
  `code`, visible printings and sets only), `limited = true`. `Meilisearch\Client` is rebound in
  `AppServiceProvider` with a Guzzle client (`scout.meilisearch.timeout` 2.0 s, `connect_timeout` 1.0 s) so a
  stopped server fails fast into the fallback.
- **Visibility:** `CardSet::shouldBeSearchable()` = game active; `GameSearchObserver` re-indexes the
  game's printings and sets. A jump only targets what the index would show (rule in `project.md`).
- **Seeding:** `CatalogueSeeder` runs inside `Printing::withoutSyncingToSearch` and
  `CardSet::withoutSyncingToSearch` (the search observers skip while Printing syncing is disabled), then
  calls `makeAllSearchable()` on both, so a seed queues a handful of chunked jobs, not one per row.
- **Storefront routes registered now** (names from `docs/storefront.md`): `home` `/`,
  `search` `/search`, `card.show` `/c/{card:slug}`, `set.show` `/s/{set:code}`. In M2 `search`,
  `card.show` and `set.show` use one invokable `App\Http\Controllers\Storefront\NotBuiltYet` that
  `abort(404)`s; M3 replaces them. Links are always `route()`. `home` renders the new layout with an
  empty main area (M3 builds the page). `welcome.blade.php` is deleted along with its test
  expectation (ExampleTest asserts `/` returns 200 — keep it).
- **Layout**: a Blade component layout `resources/views/components/layouts/storefront.blade.php`
  (Livewire 4 layout conventions — check docs), `<html lang="th">`, dark by default, Google Fonts
  `<link>` for IBM Plex Sans Thai (400/500/600) and Anuphan (500/600) as the canvas does, tokens
  from `app.css` only. Header per canvas: mobile (MHome: brand + search field opening the full-screen
  overlay; bottom nav **with only the search button** — cart/account arrive in M4/M6, do not render
  dead links), desktop (DHome: brand + wide search field). Brand text = `config('app.name')`; never an
  invented shop name, phone, address or hours. Game chips under the header from `games` where
  `is_active`, ordered by `sort`, linking nowhere yet (render as text) — skip them if DHome/MHome do
  not show them.
- **SearchBox** `App\Livewire\Storefront\SearchBox`: `wire:model.live.debounce.150ms`, groups in
  order jump row → การ์ด (6) → เซ็ต (2), rows copied from M02a (thumbnail placeholder 63:88, name,
  `set code-number · rarity letter coloured by token · language`, `<x-price>` "from" price, stock
  text: `มี n` (n > 2, shows `40+` above 40), `เหลือ n` (1–2, `--color-low`), `หมด` (0, row at 55%
  opacity)). Empty query: recent searches from the session (last 5, stored when a result or jump
  is opened) as chips; no hot list (shop data, M3+). Keyboard: ↑/↓ move, Enter opens, Escape
  closes, `/` focuses from anywhere (not inside inputs); real `<a href>` rows, ARIA combobox/listbox
  pattern. `limited` shows a one-line Thai notice. Mobile overlay = full screen; desktop = dropdown
  under the field.
- **`<x-price>`** Blade component (`resources/views/components/price.blade.php`) taking satang and
  rendering `Money::fromSatang()->format()` with `tabular-nums`. The only place a price is formatted.
- **Copy**: all Thai in `lang/th/storefront.php`; placeholders copied from the canvas
  (desktop: "ค้นชื่อการ์ด (TH / EN / JP), เลขเซ็ต หรือเลขการ์ด เช่น OP05-119, SV4a 190/190",
  mobile: "ชื่อการ์ด เลขเซ็ต หรือ OP05-119"), jump row "ไปที่เซ็ต :code" with sub-line
  "คุณพิมพ์รหัสเซ็ต — ข้ามไปไล่ดูทั้งเซ็ตตามเลขการ์ดได้เลย", group captions "การ์ด · :n ใบ",
  "เซ็ต · :n รายการ", recent "ค้นหาล่าสุด".

## Step A — engine, index, parser, service (#8)

1. `compose.yaml`, gitignore `storage/meilisearch`, docs command update.
2. `Variant::scopeAvailable()`; `Searchable` on `Printing` and `CardSet`; index settings; observers.
3. `QueryParser`, `ParsedQuery`, `QueryKind`; `CatalogueSearch` + `AutocompleteResult`.
4. Run `docker compose up -d meilisearch`, set local `.env` (`SCOUT_DRIVER=meilisearch`), run
   `php artisan scout:sync-index-settings` and `php artisan scout:import "App\Models\Printing"` /
   `CardSet`, and report document counts from Meilisearch (`/indexes/printings/stats`).

Tests (Pest, feature): parser table; `toSearchableArray` fields for a printing with mixed
variants (in_stock, from_satang ignores qty 0 and null price, conditions, available_qty,
number_plain); `shouldBeSearchable` false for trashed card / inactive game; observers queue a
re-index when a variant's qty changes (`Queue::fake` / Scout fake); `CatalogueSearch` grouping using
Scout's `collection` driver; fallback when the engine throws (bind a throwing engine) returns DB
results with `limited = true` and reports. One integration test in `->group('meilisearch')` that
runs only when `http://127.0.0.1:7700/health` answers (skipped otherwise): `OP05` text search returns
OP-05 printings with in-stock first.

## Step B — storefront layout, header, SearchBox (#9)

Routes and `NotBuiltYet`; layout; header (mobile + desktop); `SearchBox`; `<x-price>`;
`lang/th/storefront.php`; delete `welcome.blade.php`.

Tests: `/` renders the layout with the brand and the search field (Thai placeholder), `lang="th"`;
`card.show`/`set.show`/`search` resolve and 404 for now; SearchBox: typing `op05` shows the jump
row first and printings under "การ์ด"; empty query shows recent searches after one was opened;
`limited` notice shown on fallback; `<x-price>` renders `฿ 4,290` and `฿ 4,290.50`; no Thai literal
in Blade (all via `__()`).

## Out of scope

Results page and filters UI (M3), card/set pages (M3), sealed products group (M8), hot list,
images/thumbnails, cart/account icons, `lang/en`.

## Order and done

A → B, one implementer run each; after each: `verifier` (tests, build, pint, migrate:fresh --seed;
for B a 1440 and 390 browser run with screenshots of the header, the open autocomplete for `op05`,
`Luffy`, `ลูฟี่`, empty query, and Meilisearch stopped → limited notice) and `reviewer`. Commit per step
to `main` (`feat(search): …`), closing its issue. M2 is done when both issues are closed and a seeded
local app with Meilisearch running answers `OP05-119` with a jump to that printing.
