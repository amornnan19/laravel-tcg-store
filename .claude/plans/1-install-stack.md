# M0 · Install the stack

> **สรุป (ไทย):** ลง 6 package (Livewire 4, Filament 5, Scout 11 + Meilisearch, Socialite + LINE),
> เปิดแผงแอดมินที่ `/admin` และล็อกให้เข้าได้เฉพาะพนักงาน (`users.is_staff`), ตั้งค่า Scout/LINE ใน
> config และ `.env.example`, ย้าย design tokens เข้า `app.css` แล้วเขียน test พิสูจน์ ทั้งหมดยังไม่มีฟีเจอร์ร้าน
> ไม่ทำ 2FA, ไม่แก้ `users.email`, ไม่ทำ layout/ฟอนต์ — อยู่ใน milestone ถัดไป

## Context

The repo is a bare Laravel 13 (PHP 8.5.13, framework v13.44.1) Blade install. `docs/README.md` step 0
says to install the stack before any feature. A `composer require --dry-run` already resolved:
livewire/livewire v4.4.10, filament/filament v5.12.0, laravel/scout v11.11.0,
meilisearch/meilisearch-php v1.17.0, laravel/socialite v5.33.0, socialiteproviders/line 4.1.0.
The user approved adding these six dependencies.

GitHub milestone: **M0 · Install the stack** (#1). An issue is opened for it before work starts;
this plan is `.claude/plans/1-install-stack.md` (issue #1).

## Rules in force

Read `.ai/rules/project.md` and `docs/README.md`, `docs/admin.md` (Access), `docs/search.md`
(Meilisearch env), `docs/design-tokens.md` before starting. Use Boost `search-docs` (packages:
filament, livewire, scout, socialite) before writing any package-specific code — Filament 5 and
Livewire 4 APIs differ from training data. `--no-interaction` on every artisan command.
`vendor/bin/pint --dirty --format agent` before finishing. Do not commit; the main session does.

## Steps

### 1. Composer packages (in this order)
```
composer require livewire/livewire
composer require filament/filament
php artisan filament:install --panels --no-interaction
composer require laravel/scout meilisearch/meilisearch-php
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider" --no-interaction
composer require laravel/socialite socialiteproviders/line
```
- Panel id `admin`, path `/admin` (installer default). Keep whatever the installer generates
  (`app/Providers/Filament/AdminPanelProvider.php`, its registration in `bootstrap/providers.php`,
  published assets under `public/`, any composer script it adds). Do not customise the panel beyond
  what is listed below.
- If `filament:install` prompts despite `--no-interaction`, choose panel id `admin`.

### 2. Staff-only access to `/admin`
- `php artisan make:migration add_is_staff_to_users_table --table=users --no-interaction`:
  `$table->boolean('is_staff')->default(false)->after('password');` with a matching `down()`
  dropping the column. Portable for SQLite.
- `app/Models/User.php`: implement `Filament\Models\Contracts\FilamentUser` (confirm the
  interface name via search-docs for Filament 5); add
  `public function canAccessPanel(\Filament\Panel $panel): bool { return $this->is_staff; }`
  with a PHPDoc line. Cast `'is_staff' => 'boolean'`. Do **not** add `is_staff` to `#[Fillable]`
  (mass-assigning staff is a privilege escalation).
- `database/factories/UserFactory.php`: `'is_staff' => false` in `definition()`, plus a
  `staff(): static` state setting it to `true`, with a PHPDoc line like `unverified()`.
- `database/seeders/DatabaseSeeder.php`: leave as is except, if it creates a test user, make that
  user `->staff()` so a local admin login exists (password is the factory's `password`).

### 3. Scout + Meilisearch config
- `config/scout.php` published as is.
- `.env.example`: add after the cache block
  ```
  SCOUT_DRIVER=meilisearch
  SCOUT_QUEUE=true
  MEILISEARCH_HOST=http://127.0.0.1:7700
  MEILISEARCH_KEY=
  ```
- `phpunit.xml`: add `<env name="SCOUT_DRIVER" value="null"/>` so tests never reach Meilisearch.
- Also add the same four keys to the local `.env` (not committed). Meilisearch is not running and
  is not needed in M0; nothing is searchable yet.

### 4. Socialite LINE driver
- `config/services.php`: add
  ```php
  'line' => [
      'client_id' => env('LINE_CHANNEL_ID'),
      'client_secret' => env('LINE_CHANNEL_SECRET'),
      'redirect' => env('LINE_REDIRECT_URI'),
  ],
  ```
- `.env.example` (and local `.env`): `LINE_CHANNEL_ID=`, `LINE_CHANNEL_SECRET=`,
  `LINE_REDIRECT_URI="${APP_URL}/auth/line/callback"`.
- `app/Providers/AppServiceProvider.php` `boot()`: register the provider with
  `Event::listen(\SocialiteProviders\Manager\SocialiteWasCalled::class, [\SocialiteProviders\Line\LineExtendSocialite::class, 'handle']);`
  (confirm against the socialiteproviders/line README in vendor). Use imports, not FQCNs inline.
- No routes or controllers for LINE — those are M6.

### 5. Design tokens into `resources/css/app.css`
- Keep `@import 'tailwindcss';` and both existing `@source` lines.
- Replace the existing `@theme { --font-sans: 'Instrument Sans' ... }` block with the `@theme`
  block and the `@layer theme { [data-theme="light"] { ... } }` block from
  `docs/design-tokens.md`, copied verbatim (every value, including comments).
- Do not add the `.foil` class, fonts, or a layout — those belong to M3. `welcome.blade.php` is
  not touched.

### 6. Tests — `php artisan make:test --pest AdminPanelAccessTest --no-interaction`
Uses `RefreshDatabase` at the top of the file. Cases:
1. a guest requesting `/admin` is redirected to the Filament login route
   (`route('filament.admin.auth.login')`).
2. the login page `/admin/login` returns 200.
3. a user made with `User::factory()->staff()` gets 200 on `/admin`.
4. a user made with `User::factory()` (not staff) gets 403 on `/admin`.

`php artisan make:test --pest LineSocialiteDriverTest --no-interaction`:
1. with `config(['services.line' => [...dummy id/secret/redirect]])`,
   `Socialite::driver('line')` is an instance of `SocialiteProviders\Line\Provider`.

Existing `tests/Feature/ExampleTest.php` must still pass unchanged.

## Out of scope
- Filament two-factor for staff (follow-up issue in M1), `users.email` nullable and
  `line_user_id` (M6), any Filament resource (M1), layout/fonts/`.foil`/Blade components (M3),
  running Meilisearch, LINE routes, `lang/th/`.

## Done when
- `composer show livewire/livewire filament/filament laravel/scout meilisearch/meilisearch-php laravel/socialite socialiteproviders/line` lists the versions above (report them).
- `php artisan migrate:fresh --seed --no-interaction` succeeds.
- `php artisan route:list --path=admin` shows the panel routes.
- `php artisan test --compact` passes (all tests, paste output).
- `npm run build` succeeds with the new tokens.
- `vendor/bin/pint --dirty --format agent` clean.
- Then `verifier` re-runs tests + build independently; `reviewer` checks the diff (access control on `/admin`).
- One commit straight to `main` (project is not deployed): `chore: install livewire, filament, scout and socialite` — made by the main session when the user asks.
