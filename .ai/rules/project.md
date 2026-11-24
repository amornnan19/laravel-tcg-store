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

## Commits

Conventional Commits, English, imperative, lower case after the colon, under 72 characters:
`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`, `perf:`, `style:`. Scope in parentheses
where it narrows usefully, such as `feat(buylist): ...`.
