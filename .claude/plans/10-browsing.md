# M3 · Browsing

> **สรุป (ไทย):** 4 ขั้น ทำทีละขั้น — **A** คอมโพเนนต์ design system (การ์ดไทล์, ภาพการ์ด+แสง foil, badge ต่าง ๆ, empty/skeleton) + หน้าแรก
> **B** หน้าผลค้นหา `/search` และ `/g/{game}` พร้อมฟิลเตอร์ (bottom sheet มือถือ / sidebar เดสก์ท็อป) จาก facet ของ Meilisearch
> **C** หน้าการ์ด `/c/{slug}` ตาราง variant แท็บภาษา จำนวน แถบล่าง (ยังไม่มีปุ่มเพิ่มตะกร้า — M4) กราฟราคา 90 วันจากข้อมูลตัวอย่าง
> **D** หน้าไล่ดูทั้งเซ็ต `/s/{code}` เรียงตามเลขการ์ด
> ส่วนที่ต้องใช้ระบบที่ยังไม่มี (ตะกร้า, บัญชี, แจ้งเตือน, การ์ดเกรด, สินค้ากล่อง, รีวิว, ขายดี) ไม่แสดง

## Context

M2 shipped the storefront layout, header and SearchBox; card/set/search routes still 404 via
`NotBuiltYet`. M3 is "Browsing" in `docs/README.md`: results with filters, the card page with the
variant table, the set browser — plus the home page, which `docs/storefront.md` lists as M01/D01.
User decisions: the add-to-cart control is **not rendered** until M4 (no dead buttons); seed
**sample 90-day price history** so the chart shows in the demo (the canvas labels it
"ข้อมูลตัวอย่าง").

## Rules in force (read before each step)

`.ai/rules/project.md` (all rules, incl. search-hit hydration via `searchVisible()`, placeholder-only
card art in `<x-card-art>`, focus rings via `:focus-visible`, no invented shop data, Thai only via
`lang/th/`), `docs/storefront.md` (components, card page rules, responsive, states, performance),
`docs/design-tokens.md`, `docs/search.md` (Filters table, facets, `#[Url]`), `docs/data-model.md`.
Boost `search-docs` (livewire, laravel/scout, laravel) before Livewire 4 / Scout APIs; skills
`livewire-development`, `tailwindcss-development`, `scout-development`, `laravel-best-practices`,
`testing-best-practices`. No new dependency. Pint. No commits by the agent.

Canvas (user's own design; local copies, gitignored): `storage/canvas-ref/project/` —
`MHome`/`DHome` (M01/D01), `MResults`/`DResults` (M03/D02), `MSearchFilter` (M02b filter sheet),
`MCardDetail`/`DCardDetail` (M04/D03), `DSetBrowser` (D04), `Components`, `States`, `Sitemap`.
Re-fetch from https://claude.ai/artifact/2NKx859ujDu9cA7n8UWLuE if missing. Values from tokens, never
artboard hex.

## Decisions (do not reopen)

- **Not rendered in M3** (no dead controls, no invented data): add-to-cart / cart badge (M4), wishlist
  heart, restock bell "แจ้งของเข้า", "hide what I own", collection progress, "add all missing" (M6),
  graded tiles / graded listing (M8), sealed products (M8), reviews (no data model), "ขายดี 7 วัน"
  (needs orders, M5), theme toggle, LINE chat button. Out-of-stock rows/tiles show "หมด" dimmed.
- **Bottom nav (phone):** home + search only (cart/account arrive with their milestones).
- **From-price for sold-out:** add `from_satang_any` (lowest non-null price over all variants,
  ignoring availability) to `Printing::stockSummary()` and the `printings` index document; sold-out
  tiles/rows show it in `text-faint`. Ranking and the in-stock `from_satang` stay as they are.
- **Rarity display:** `<x-rarity-badge>` shows the stored code upper-case with its
  `--color-rarity-*` token (letters/borders only, never fill).
- **Set code display:** `Printing::display_code` / a `CardSet::display_code` (upper-case) everywhere
  a code is shown; URLs keep the stored code.
- **Card page identity:** `/c/{card:slug}` with optional `?p={printing_id}` selecting the printing
  shown in the header (default: the card's in-stock-first, cheapest printing). SearchBox rows link
  with `?p=`. Language tabs = languages of the card's visible printings. Within a tab, variant rows
  are grouped per printing when the card has more than one printing in that language (set code +
  number as the group label). Changing the tab resets selection to the cheapest available row in
  that language and quantity to 1; stepper capped at available qty with the cap shown as a sentence
  (docs/storefront.md "Rules that are easy to get wrong"). Sticky bar shows selected variant ·
  quantity · total via `<x-price>` — no add button.
- **Price chart:** server-rendered inline SVG from `price_points` of the selected variant (90 days),
  no library, no client fetch; hidden when fewer than 2 points. Caption "90 วัน · ข้อมูลตัวอย่าง"
  only when data comes from the seeder (always true in this demo; a `config('shop.sample_price_history')`
  flag, default true, drives the caption).
- **Sample price history seeder** `PricePointSeeder`: for NM variants of printings with rarity
  `rr|sar|sec` that have a price, 90 daily rows around the current price (±15% random walk,
  `fake()->seed(2)`, integers in satang, `sold_qty` 0–3), inserted in chunks. Called from
  `DatabaseSeeder` after the catalogue.
- **Results** (`Storefront\ResultGrid` + `Storefront\FilterPanel`, one FilterPanel rendered as phone
  bottom sheet and desktop sidebar): Scout query on `printings` with Meilisearch filters + facet
  distribution for every filter in the docs/search.md table; filters in the URL via `#[Url]`;
  "in stock only" default on; sort options price low–high / high–low / number / newest; 24 per
  page with "โหลดอีก 24 รายการ"; grid (2 cols phone, 5–6 desktop) and list mode; the sheet button
  shows the live count "ดูผล N รายการ". `/g/{game:slug}` = results pre-filtered to that game.
  Facets shown per `games.filters`. Empty state per docs ("ไม่พบ … ที่ตรงเงื่อนไข · มี N ใบถ้าเอา
  ตัวกรอง X ออก" with a button removing that filter) — compute N by re-querying without the most
  recently added filter. Meilisearch down → DB fallback (text + game/set/language/in-stock only),
  no facet counts, the limited notice. QueryParser jumps still apply: a Printing match on Enter
  from the results field redirects to the card page.
- **Set browser** (`Storefront\SetBrowser`): `/s/{set:code}` route key = stored code; grid ordered by
  `number_plain` then `number`; range chips of 50 numbers (last chip to the end); "เฉพาะที่ร้านมีของ"
  toggle (default off here, since collectors browse the whole set); 48 per load; header with set
  name, game, card_count, release date (Thai date), in-stock count. In-set search field filters by
  name or number.
- **Home** (M01/D01): hero = the latest set by `released_on` (else latest id) linking to its set
  browser; "ซิงเกิลมาใหม่" = most recently created in-stock printings (12); "เซ็ตล่าสุด" = sets by
  release date; game chips already in the header. Sections with no data are not rendered.
- **Blade design-system components** (build in Step A, reuse everywhere): `<x-card-tile>`,
  `<x-card-art>` (extend the M2 one: `printing`, `size`, `foil` → the foil sheen from
  docs/design-tokens.md, reduced-motion off), `<x-condition-badge>`, `<x-rarity-badge>`,
  `<x-language-badge>`, `<x-stock-indicator>`, `<x-filter-chip>`, `<x-trust-row>` (copy from the
  canvas; no invented guarantees — only "ภาพจริง" when `has_real_photo` and generic copy the canvas
  shows), `<x-empty-state>`, `<x-skeleton>`. `<x-price>` gains `compare-at` (struck through).
- **Scope:** `Printing::scopeWithStockSummary()` or eager-load pattern so a 24-tile grid is ≤ 3
  queries (docs: `Card::withCheapestVariant()` — implement as the printing-level equivalent since
  tiles are printings; name it `withCheapestVariant` on `Printing`). Test the query count.
- Remove `NotBuiltYet` routes as each page lands; delete the controller when none remain.

## Step A — design system + home (#10)

Components above, `PricePoint` untouched, home page (M01/D01) with the decisions above, mobile bottom
nav home+search. Tests: each component renders its states (foil class only when foil; rarity colour
token; condition badge label from enum; stock indicator thresholds same as SearchBox); home renders
hero/new singles/latest sets from factories and omits empty sections; query count on home ≤ 6.

## Step B — results + filters (#11)

`search` and `game.show` routes, ResultGrid, FilterPanel, `from_satang_any` in summary + index
(re-sync settings and re-import in the report), SearchBox Enter on text goes to `/search?q=`. Tests:
filters map to Meilisearch filter strings (unit-level on the query builder), URL params round-trip,
default in-stock on, facet visibility per `games.filters`, empty-state suggestion removes the right
filter, fallback path, load-more, ≤ 3 queries per 24 tiles. One `->group('meilisearch')` test for a
real facet count.

## Step C — card page (#12)

`card.show` with `?p=`, VariantTable, sticky bar, chart, "other cards in the set" (12, same set,
by number), `PricePointSeeder`, `config/shop.php`. Tests: tab reset rule, stepper cap + sentence,
sticky bar total for qty × price, out-of-stock rows visible and dimmed with "หมด", `?p=` selects the
printing, chart hidden with < 2 points, trashed card 404, query count bounded.

## Step D — set browser (#13)

`set.show`, SetBrowser, remove `NotBuiltYet`. Tests: ordering by number, range chips, in-stock
toggle, in-set search, inactive game 404, load-more.

## Out of scope

Cart, reservations, accounts, alerts, graded, sealed, reviews, best-sellers, thumbnails/WebP (no
images), `lang/en`.

## Order and done

A → B → C → D, one implementer run each; after each: `verifier` (tests, build, pint, migrate:fresh
--seed + queue drain, browser run at 1440×900 and 390×844 with screenshots into
`storage/screens/m3-<step>/`), `reviewer`, and `designer` for B and C (the two screens the shop lives
on). Commit per step to `main` (`feat(storefront): …`), closing its issue. M3 is done when all four
issues are closed and a seeded local app lets a visitor go home → search → filter → card page → set
browser with no 404 and no dead control.
