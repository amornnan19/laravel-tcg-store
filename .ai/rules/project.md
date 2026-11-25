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
- **Search hits come back from Meilisearch without `searchVisible()`; hydrate them through `->query(fn ($b) => $b->searchVisible())` so a trashed card or inactive game never reaches a view before the queued re-index runs.**
- **Card art is placeholder only, rendered by `<x-card-art>`. Never fetch, store, hotlink or seed official card images from any game; staff-uploaded variant photos are the only real photos.**
- **A storefront count shown against `sets.card_count` counts distinct card numbers, never raw printings: printings include secret rares past the set size and every language.**
- **Focus rings use `:focus-visible` (`focus-visible:` / `has-focus-visible:`), never `focus-within:`, and never stack on an element whose border already turns accent.**

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
