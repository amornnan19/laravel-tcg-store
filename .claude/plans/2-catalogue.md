# M1 · Catalogue

> **สรุป (ไทย):** M1 สร้างแกนแคตตาล็อกการ์ดทั้งหมด แบ่งเป็น 4 ขั้น ทำทีละขั้น (1 issue ต่อขั้น):
> **A** ตาราง games → sets → cards → printings → variants (+ variant_photos, price_points), enum, `Money`,
> factory, policy และ seeder ที่ใช้ข้อมูลเซ็ตจริงจาก API สาธารณะ (ดึงครั้งเดียว เก็บเป็น JSON ใน repo, ไม่เก็บรูปการ์ด)
> **B** หน้าแอดมิน Games / Sets / Cards / Printings (แก้ variant ทั้งชุดในหน้า Printing, ปุ่ม Generate variants, รูปถ่ายจริง)
> **C** หน้า Variants แบบ spreadsheet: แก้ราคา/จำนวนในตาราง, bulk action, นำเข้า CSV
> **D** เปิด 2FA ให้ staff
> แอดมินเป็นภาษาไทย ทุกข้อความผ่าน `lang/th/` เพื่อเพิ่มภาษาอังกฤษทีหลังได้ง่าย

## Context

M0 (#1) installed the stack. M1 builds the catalogue spine every later milestone reads from: the
card → printing → variant chain (`docs/data-model.md`) and the admin screens staff use to price a set
(`docs/admin.md`). Done means a staff user can seed, browse, generate, price and bulk-import variants
for one real set per game in `/admin`, in Thai.

Decisions taken with the user: seed data is real, fetched once from public APIs into committed JSON;
the admin panel is Thai (`APP_LOCALE=th`), with every string in `lang/th/` so English can be added
later by adding `lang/en/` and a locale switch.

## Rules in force (read before each step)

`.ai/rules/project.md` (money as satang ints, variant is the sellable unit, enums in `app/Enums`,
translations in `lang/th/`, `make:` + `--no-interaction`, new table ⇒ factory + seeder, no new
dependency), `docs/data-model.md` (Catalogue, Conventions, Indexes, SQLite note), `docs/admin.md`,
`docs/architecture.md` (Conventions), `docs/search.md` (Filters table). Use Boost `search-docs`
(packages: filament, livewire, laravel) before any Filament 5 API — forms, tables, actions,
importers, relation managers, MFA all changed since v3. Use Boost skills
`laravel-best-practices`, `testing-best-practices`. Pint before finishing each step. No commits by
the agent.

## Decisions (do not reopen)

- **Rarity stays a string** (`rarity_code`), not an enum — it differs per game. The seeder maps each
  API rarity onto `c, u, r, rr, sar, sec` with an explicit per-game map in the importer; anything
  unmapped becomes `r` and is listed in the fetch command's output.
- **Money:** columns are plain `integer` (nullable where stated) on the model, not cast to an object
  in M1. `app/Support/Money.php` is a `final readonly` value object: `fromSatang(int)`,
  `fromBaht(string)` (accepts `^\d+(\.\d{1,2})?$` only, string math, never float; throws
  `InvalidArgumentException` otherwise), `satang(): int`, `toBahtString(): string` (`4290` or
  `4290.50`), `format(): string` (`฿ 4,290` / `฿ 4,290.50`). Filament uses it through one component.
- **`variants.price_satang` is nullable** — generated variants start unpriced and "price is null" is
  a filter (`docs/admin.md`). Factories always set a price.
- **No card images in seeds.** `image_path` stays null; the canvas uses placeholders and the shop
  supplies its own photos. No thumbnail generation in M1 (it would need an image library =
  new dependency); revisit in M3.
- **`price_points`:** table, model, factory only. The nightly job comes with orders (M5+).
- **Slugs:** generated in the model's `creating` hook when blank — `Str::slug(name_en)`, then
  `-2`, `-3`… until unique including soft-deleted rows. Applies to `games` (slug given in seed) and
  `cards`.
- **SKU:** generated in `Variant` `creating` when blank:
  `strtoupper("{set.code}-{number}-{language}-{condition}-{finish}")` with every character outside
  `[A-Z0-9-]` replaced by `_`, finish as `N`/`F`. Example `OP05-119-JA-NM-F`.
- **Enum labels in Thai, from `lang/th/enums.php`.** Enums implement Filament's `HasLabel`
  (and `HasColor` for condition) so selects and badges reuse them. `CardCondition::color()` returns
  the design-token name (`cond-nm` …) for the storefront; `getColor()` returns a Filament palette
  name (`success, info, warning, warning, danger`) for the panel.
- **Policies:** one per model (`make:policy --model`), every ability returns
  `$user->is_staff === true`. The panel gate already blocks customers; policies are the second wall
  `docs/admin.md` asks for.
- **CSV import uses Filament's built-in Importer** (no dependency). Rows that match nothing are
  failed rows with a reason, never created. Requires Filament's import migrations, the
  notifications table, job-batches table and `->databaseNotifications()` on the panel.
- **Percent adjust uses basis points**, integer math: `bps = percent × 100` parsed from string
  (`7.5` → `750`), `new = intdiv(price × (10000 + bps) + 5000, 10000)`. Null prices are skipped.

## Step A — schema, domain, seed data (#2)

Migrations (`make:model -mf` per table, then edit), columns exactly as `docs/data-model.md`
Catalogue tables, plus:
- `games`: `filters` json cast `array`; `sort` smallint; `is_active` bool default true.
- `sets`: unique `(game_id, code)`. Model name `CardSet` (`Set` is awkward in PHP), table `sets`.
- `cards`: softDeletes. The doc's `attributes` json column is named **`traits`** (cast `array`),
  because `attributes` collides with Eloquent's internal `$attributes` property. Update the
  `cards` table in `docs/data-model.md` to say `traits` and why, in one line.
- `printings`: softDeletes; unique `(set_id, number, language)`; index `(card_id, language)`;
  `language` cast to `App\Enums\Language`.
- `variants`: unique `(printing_id, condition, finish)`; unique `sku`; index `qty` (plain index — a
  partial index is not portable; note this in `docs/data-model.md` Indexes); casts to
  `CardCondition`, `Finish`; `has_real_photo` bool default false; `qty` default 0.
- `variant_photos`, `price_points` (unique `(variant_id, on_date)`).
- Foreign keys `constrained()`; `cascadeOnDelete` only from variants → variant_photos / price_points.

Enums in `app/Enums`: `Language` (`ja, en, th`), `CardCondition` (`nm, lp, mp, hp, dmg`, `sort()`
1..5), `Finish` (`normal, foil`).

Relations both ways along the chain; scope `Printing::forLanguage(Language)`. (`Variant::available()`
needs reservations — M4. `Card::withCheapestVariant()` — M3.)

Factories for all seven models with realistic states (`Variant::foil()`, `->unpriced()`,
`Printing::withFoil()`), plus `Money`.

**Seed data:** `php artisan make:command FetchCatalogueSeedData` → `catalogue:fetch-seed-data`, uses
the `Http` facade, writes `database/seeders/data/{game-slug}.json`
(`{game:{…}, set:{code,name_en,name_th:null,released_on,card_count}, cards:[{name_en, name_ja?,
card_type?, traits:{}, number, rarity_code, language, has_foil}]}`). Sources and sets:

| game slug | source | set |
|---|---|---|
| `pokemon` | api.pokemontcg.io v2 | `sv3pt5` (151), language `en` |
| `magic` | api.scryfall.com | `blb` (Bloomburrow), `en` |
| `yugioh` | db.ygoprodeck.com API v7 | `Rage of the Abyss` (`ROTA`), `en` |
| `one-piece` | optcgapi.com | `OP-05`, `en` |
| `digimon` | digimoncard.io API | `BT16`, `en` |

If a source lacks the named set or is unreachable, pick its most recent main booster set that it does
have, and report the substitution. Respect each API's rate-limit guidance; send a generic
User-Agent (`tcg-store-seed/1.0`), no personal data. Commit the JSON; the seeder never touches the
network.

Seeders: `GameSeeder` (5 games, Thai and English names, `sort`, `filters` per game from the
`docs/search.md` Filters table — pokemon `[rarity, card_type, language]`, one-piece
`[rarity, card_type, color, language]`, yugioh `[rarity, card_type, attribute, language]`, magic
`[rarity, card_type, color, language]`, digimon `[rarity, card_type, color, language]`),
`CatalogueSeeder` (reads the JSON: set, cards, printings; then one `nm/normal` variant per printing
with a plausible price by rarity from a fixed table in the seeder and `qty` 0–8 from a seeded Faker —
`fake()->seed(1)` — plus `nm/foil` when `has_foil`). `DatabaseSeeder` calls both after the local
staff user.

`.env.example`: `APP_LOCALE=th`, `APP_FALLBACK_LOCALE=en`. `lang/th/enums.php`.

Tests (Pest, feature): Money (parses `4290`, `4290.5`, rejects `1.234`, `-1`, `abc`; formats);
`CardCondition` sort order; SKU generated as specified; card slug collision gets `-2`; unique
`(printing, condition, finish)` enforced; `DatabaseSeeder` creates 5 games, 5 sets, >0 printings and
every variant has a sku; `catalogue:fetch-seed-data` normalises one tiny faked payload per source
(`Http::fake`) into the JSON shape.

## Step B — Filament: Games, Sets, Cards, Printings (#3)

Navigation group `แคตตาล็อก` (from `lang/th/admin.php`), order Games, Sets, Cards, Printings,
Variants. All labels via `__()`; model labels Thai.

- **Games:** table (name_th, slug, sort, is_active toggle column), form with `filters` as a
  `CheckboxList` of the facet keys in `docs/search.md` (`rarity, card_type, color, attribute,
  language`).
- **Sets:** game select, code (unique per game), names, released_on, card_count, sort. Filter by game.
- **Cards:** game, names, card_type, `traits` as `KeyValue`; soft-delete with `TrashedFilter`,
  restore; delete confirmation names the card. Searchable by `name_en`, `name_ja`, `name_th`.
- **Printings:** list with set, number, language, rarity, foil; filters set/game/language. Edit page
  has a `Repeater::relationship('variants')`: condition, finish, price (MoneyInput), compare-at
  (MoneyInput), qty, note_th, has_real_photo. Header action **Generate variants** →
  `app/Actions/GenerateVariants.php` (`handle(Printing): int` creates only the missing
  condition × finish rows — 5, or 10 with foil — unpriced, qty 0; returns how many were created; the
  action's notification says that number). Soft deletes as Cards.
- **MoneyInput:** `app/Filament/Forms/Components/MoneyInput.php`, a `TextInput` subclass/factory
  that shows baht (`Money::fromSatang()->toBahtString()`), validates with the `fromBaht` regex,
  dehydrates to satang, prefix `฿`. Used for every money field in the panel.
- **Variant photos:** a `PhotosRelationManager` on the Variants resource edit page (built in C):
  `FileUpload` to the `public` disk, `variant-photos/`, reorderable by `sort`. Run
  `php artisan storage:link`.

Tests: each resource's list page renders for staff and is forbidden for a non-staff user; creating a
Card through the Livewire form generates its slug; Printing edit with the repeater saves `฿ 4,290.50`
as `429050`; Generate variants creates 10 for a foil printing, 5 without, 0 on a second run.

## Step C — Filament: Variants as a spreadsheet + CSV import (#4)

- List: columns set code, number, card name, language, rarity, condition badge, finish, price
  (`TextInputColumn` in baht → satang through `Money`), qty (`TextInputColumn`, integer ≥ 0),
  has_real_photo. Filters: set, game, language, rarity, condition, "ยังไม่ตั้งราคา" (price null),
  "สต็อกเป็นศูนย์" (qty 0). Eager-load printing.card and printing.set (no N+1).
- Bulk actions, each confirmed and naming the count: set price (MoneyInput), adjust price by percent
  (basis-point rule above, may be negative, floor at 0), set qty, add to qty (may be negative, floor
  at 0), mark real photo taken.
- Edit page: same fields as the repeater row, plus the photos relation manager from B.
- `VariantImporter` (Filament Importer): columns `sku, set_code, number, language, condition,
  finish, price, qty`. Resolve by `sku` when present, else by `(set code within any game, number,
  language, condition, finish)`; ambiguous or no match ⇒ the row fails with a Thai reason; never
  create. `price` is baht (string) through `Money::fromBaht`. Panel gets
  `->databaseNotifications()`; publish Filament's import migrations, `make:notifications-table`,
  `make:queue-batches-table`.

Tests: price column update stores satang; each bulk action (including percent rounding:
`9999` +7.5% ⇒ `10749`, null price untouched, negative adjust floors at 0); importer updates by sku,
by composite key, fails an unmatched row without creating anything.

## Step D — staff two-factor (#5)

Enable Filament 5 app (TOTP) multi-factor authentication on the admin panel, required for every
staff user (`google2fa` already ships with Filament). Add the columns its docs require on `users`
via a migration, hidden/encrypted as the docs say. Local seeded staff user: document in the PR
how to enrol. Test: a staff user without MFA set up is sent to the enrolment page.

## Out of scope

Storefront pages, `<x-price>`, search indexing (M2), `Variant::available()` and reservations (M4),
graded/sealed (M8), thumbnails, `lang/en/` and a locale switcher.

## Order and done

A → B → C → D, one implementer run each, each followed by `verifier` (`php artisan test --compact`,
`migrate:fresh --seed`, pint, `npm run build`) and `reviewer` (A: schema/indexes/money; B, C:
policies, mass actions, import; D: auth). Commit per step straight to `main` with
`feat(catalogue): …`, closing that step's issue. M1 is done when all four issues are closed and a
seeded local `/admin` shows 5 games, 5 real sets and priceable variants in Thai.
