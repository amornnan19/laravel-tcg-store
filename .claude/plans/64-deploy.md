# Deploy to Coolify: demo-tcg-store.amornnan.xyz (issue #64)

> **สรุป (ไทย)**
>
> **ขั้น 1: implementer ทำในโค้ด**
> - **คำสั่ง `shop:make-staff`:** สร้างหรือเลื่อนผู้ใช้เป็น staff ใน production แล้วหมุน remember token และตัด session เก่าตามกฎของโปรเจกต์
> - **seed แบบ demo:** ตั้ง `SHOP_DEMO_DATA=true` เพื่อ seed ข้อมูลตัวอย่างบนเว็บ demo ได้ ส่วนบัญชีที่รู้รหัสผ่านยังสร้างเฉพาะบนเครื่อง local
> - **Postgres:** CI รันเทสต์ทั้งบน SQLite และ Postgres 17 แล้วแก้ทุกอย่างที่พังบน Postgres
> - **ไฟล์ deploy:** `Dockerfile` (FrankenPHP แบบเดียวกับ qr-order) และ `compose.production.yaml`
>   - มี 4 service: web, queue worker, scheduler, meilisearch
>   - ทุก service ใช้ volume รูปภาพร่วมกัน
> - **`.env.example`:** เติมค่าที่ยังขาดให้ครบ
>
> **ขั้น 2: ผมตั้งค่าบน Coolify**
> - **project ใหม่ "tcg-store":** มี Postgres แบบ standalone ซึ่งทำ backup ได้ และแอปแบบ docker compose จาก repo ที่เป็น public
> - **ตั้ง env ครบก่อน deploy ครั้งแรก:** รวมข้อมูลร้านแนว JP ที่ผมคิดให้ตามตารางด้านล่าง
> - **หลัง deploy:** seed ข้อมูล demo, import ดัชนี search และสร้างบัญชี staff
>
> **สิ่งที่คุณต้องทำ**
> - **DNS:** เพิ่ม A record `demo-tcg-store.amornnan.xyz → 187.127.106.6` ที่ z.com ตอนนี้ชื่อนี้ยังไม่ resolve และโดเมนไม่มี wildcard
> - **LINE:** credential ทั้งหมด และ callback URL `https://demo-tcg-store.amornnan.xyz/auth/line/callback`
> - **Resend SMTP:** API key และโดเมนผู้ส่งที่ verify แล้ว
> - **อีเมลของ staff คนแรก:** ถ้าไม่ระบุ ผมจะใช้อีเมลของคุณ
>
> **ระหว่างรอค่าพวกนี้ เว็บยังเปิดใช้ได้:** ล็อกอินด้วย LINE ยังซ่อนอยู่ และอีเมลเก็บไว้ใน log ก่อน

## Context

M1–M9 are done and merged, the repo is public, and CI is green. The user wants the demo deployed to their own Coolify (`coolify.ghostshift.tech`, Coolify 4.7.6, server `localhost`) at `demo-tcg-store.amornnan.xyz`:
- Postgres on Coolify;
- Meilisearch on Coolify;
- Resend SMTP for mail;
- LINE, which the user is setting up.

Shop facts are delegated ("ขอเท่ๆแนว JP"), and so is the engineering work.

Issue #64 holds the full checklist.

The audit found these gaps:
- no way to create staff in production;
- `OrderSeeder` and `PricePointSeeder` are not guarded;
- `.env.example` is missing keys;
- no Dockerfile or production compose;
- the suite has only ever run on SQLite.

`amornnan19/qr-order`, a Laravel 13 + Filament app on PHP 8.5 that is already on this Coolify, is the proven pattern to copy for the image:
- `Dockerfile`;
- `docker/Caddyfile`;
- `docker/entrypoint.sh`;
- `docker/php.ini`.

## Rules in force

Every rule in `.ai/rules/project.md` applies. These matter most here:
- `trustProxies`: the container publishes no port, so only Coolify's Traefik reaches it.
- Seeded known-password users exist only when `isLocal()`.
- Promoting a user to staff rotates `remember_token` and ends their sessions.
- Never print an invented address or account: everything comes from env and is clearly labelled demo.
- Jobs get `$tries`.
- The second-app `APP_URL` rule.
- "Deployment status": edited after the first deploy, not before.

Before coding: Boost `search-docs` for console commands, seeding, and the Postgres driver differences. Load the skills `laravel-best-practices` and `testing-best-practices`.

## Step 1: engineering (one implementer round)

### 1. `php artisan shop:make-staff {email} {--name=}`

New command `app/Console/Commands/MakeStaff.php`, via `make:command`.

- Normalise the email with `User::normalizeEmail`.
- **No user with that email:** create one with `is_staff = true`, the given `--name` (default: the part of the email before `@`) and a random 24-character password. Print the password **once**. Set `email_verified_at` to now.
- **User exists:** set `is_staff = true`. If it was not staff before:
  - set a new random `remember_token` (`Str::random(60)`);
  - delete that user's rows from `sessions` (the database session driver);
  - say so in the output.

  Print no password; tell them to use "forgot password", which refuses staff. Instead, add an option `--reset-password` that sets a new random password and prints it.
- Refuse with exit 1 when the email is invalid.
- Output is in English (operator text, not customer copy).
- **Tests:** create (staff, verified, password works), promote (token rotated, sessions deleted, `canAccessPanel` true), `--reset-password`, and an invalid email.

### 2. Demo data in production

- `config/shop.php`: `'demo_data' => (bool) env('SHOP_DEMO_DATA', false)`, with a comment.
- **`DatabaseSeeder`:**
  - Always run `GameSeeder` and `CatalogueSeeder`.
  - Run the demo content seeders (`PricePointSeeder`, `OrderSeeder`, `GradedItemSeeder`, `SealedProductSeeder`, `PreorderSeeder`, `BuylistPriceSeeder`) only when `app()->isLocal() || config('shop.demo_data')`.
  - Run `SavedDeckListSeeder` and `StoreCreditSeeder`, and the demo-customer submission inside `BuylistPriceSeeder`, only when `app()->isLocal()`. They need the known-password customer.
- **Existing `isLocal()` guards** in `GradedItemSeeder`, `SealedProductSeeder` and `PreorderSeeder`: change them to the same `local || demo_data` predicate. Put the predicate in one helper, for example a `Database\Seeders\Concerns\SeedsDemoData::shouldSeedDemo()` trait or a static on `DatabaseSeeder`.
- **`OrderSeeder` in demo mode:** it must not attach orders to a known-password user. It creates guest or factory users with random passwords, which the factory already does. Verify this.
- **Tests:**
  - `APP_ENV=production` with no flag gives catalogue only: no orders, price points, graded, sealed or buylist prices;
  - with the flag, the demo content exists and `customer@example.com` / `test@example.com` do not;
  - local behaviour is unchanged.

### 3. Postgres

- **CI:** `.github/workflows/tests.yml` becomes a matrix over `db: [sqlite, pgsql]`. The pgsql leg runs a `postgres:17` service with a health check, adds `pdo_pgsql` to the extensions, and sets `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=testing`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres` as env. These override the phpunit.xml sqlite values: add `force="false"` semantics if needed, or switch phpunit.xml's DB env to read from the environment, and say which. Pint and the build run once, on the sqlite leg.
- **Locally:** run the suite against Postgres (`docker run -d --rm -p 54329:5432 -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=testing postgres:17`, port 54329, and stop it after). Fix every failure in the app code or the migrations, never by skipping tests. Expected classes of failure:
  - case-sensitive `LIKE` (use `whereLike`/`ILIKE` where search needs it);
  - `orderBy` on nullable columns;
  - JSON column comparisons;
  - SQLite-only raw SQL;
  - integer overflow on `unsignedInteger` (Postgres has no unsigned);
  - boolean casts in raw queries;
  - sequence resets in tests;
  - `DB::listen` lock-order tests whose SQL text differs (`"table"` quoting and `for update` now present).

  Report each fix.

### 4. Production image and compose

- **Copy qr-order's `Dockerfile`, `docker/Caddyfile`, `docker/entrypoint.sh`, `docker/php.ini`, and `.dockerignore` (if it exists).** Adapt them:
  - extensions: `bcmath exif gd intl pdo_pgsql zip pcntl`;
  - `php.ini` `upload_max_filesize` must exceed the slip and photo limits (`shop.slip_max_kb` is 5120): use 8M, with `post_max_size` 12M;
  - keep the FrankenPHP base `dunglas/frankenphp:1-php8.5-trixie`, non-root, `:8080`, and the `/up` health check;
  - `entrypoint.sh` runs migrate (with retries), `storage:link` if missing, and `php artisan optimize` **only when `CONTAINER_ROLE=web`**. The worker and scheduler roles skip migrate and wait for `/up` on the web service instead, or just retry. Keep it simple and say which you chose.
- **New `compose.production.yaml`** (the dev `compose.yaml` stays as is). It has one built image and four services:
  - `web`:
    - `build: .`, `CONTAINER_ROLE=web`, `expose: ["8080"]`, **no `ports:`**;
    - volume `storage-app:/app/storage/app`;
    - health check `/up`.
  - `worker`:
    - the same image, `CONTAINER_ROLE=worker`;
    - `command: php artisan queue:work --tries=1 --max-time=3600 --sleep=1`;
    - the same volume, no health check.
  - `scheduler`:
    - the same image, `CONTAINER_ROLE=scheduler`;
    - `command: php artisan schedule:work`;
    - the same volume.
  - `meilisearch`:
    - `getmeili/meilisearch:v1.58.0` (the dev version);
    - `MEILI_ENV=production`, `MEILI_MASTER_KEY=${MEILI_MASTER_KEY}`, `MEILI_NO_ANALYTICS=true`;
    - volume `meili-data:/meili_data`, no ports.
  - Every app service gets the same env list, each value written as `${VAR}` so Coolify pre-creates them:
    - `APP_*`, `DB_*`, `SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST=http://meilisearch:7700`, `MEILISEARCH_KEY=${MEILI_MASTER_KEY}`;
    - `MAIL_*`, `LINE_*`, `SHOP_*`, `SHIPPING_*`, `BUYLIST_CREDIT_BONUS_BP`, `SHOP_DEMO_DATA`;
    - `SESSION_SECURE_COOKIE`, `LOG_LEVEL`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `FILESYSTEM_DISK=local`.
- **Check the image builds locally** with `docker build -t tcg-store:local .`. Then bring the compose up against a throwaway Postgres container: web starts, `/up` returns 200, and the worker processes a job. Tear it down afterwards. Report the commands and their output.

### 5. `.env.example`

Add, with one-line comments, every key in `config/` that the app reads and the file lacks:
- `SHOP_TIMEZONE`, `SHOP_SAMPLE_PRICE_HISTORY`, `SHOP_BUYLIST_ADDRESS`, `SHOP_DEMO_DATA`;
- `BUYLIST_CREDIT_BONUS_BP`;
- the `SHIPPING_*` keys;
- `MEILI_MASTER_KEY` (compose only).

Use placeholder or empty values and the code defaults.

### 6. Docs

`README.md` "Running it locally" stays. Add a short "Deploying" section that points at `compose.production.yaml`, names the four services and the `shop:make-staff` command, and gives the post-deploy steps:
- `php artisan db:seed --force` (with `SHOP_DEMO_DATA` for a demo);
- `scout:sync-index-settings` and the three `scout:import` commands;
- `shop:make-staff`.

**Out of scope:** card payments, S3 storage, a demo banner, and editing "Deployment status" (that is done after the deploy).

**Done when:**
- the full suite passes on SQLite **and** Postgres locally;
- Pint passes and `npm run build` succeeds;
- the image builds and the compose smoke test passes;
- new tests exist for items 1 and 2.

The implementer does not commit.

## Step 2: verify, review, commit

- One `verifier` run: both suites, Pint, the build, and the `docker build` plus compose smoke test, run 3× for `/up`.
- One `reviewer` run, code only (no UI changed). Focus:
  - the Dockerfile and entrypoint;
  - no published ports;
  - secrets never baked into the image;
  - the seeder guards;
  - the make-staff session and token rule;
  - the Postgres fixes.
- Commits:
  - `feat(ops): add shop:make-staff and demo-data seeding`;
  - `fix: run the suite on postgres`, if any app code changed for Postgres;
  - `ci: run the suite on postgres too`;
  - `chore(deploy): add the production image and compose`.

## Step 3: Coolify (main session, via the `coolify` skill REST API)

1. **Project** `tcg-store`, environment `production`.
2. **Standalone PostgreSQL 17** `tcg-store-db`, created with `instant_deploy: false`. Set the password first (the skill's rule), then start it. The daily backup is enabled in the UI by the user.
3. **Application** from the public repo:
   - `POST /api/v1/applications/public`;
   - `git_repository: amornnan19/laravel-tcg-store`, `git_branch: main`;
   - `build_pack: dockercompose`, `docker_compose_location: /compose.production.yaml`;
   - `docker_compose_domains: [{name: web, domain: https://demo-tcg-store.amornnan.xyz}]`;
   - `instant_deploy: false`.

   Then turn on "connect to predefined network" so `web` reaches the standalone DB by its internal hostname, and confirm the field from the docs or the UI. Never probe with a write.
4. **Env**, via PATCH for compose-referenced keys:
   - generate `APP_KEY` locally (`php artisan key:generate --show`), never print it in chat;
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://demo-tcg-store.amornnan.xyz`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`;
   - `DB_*` from the created DB;
   - `MEILI_MASTER_KEY` random;
   - `MAIL_MAILER=log` until the user supplies Resend, then `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD=<Resend API key>`;
   - `SHOP_DEMO_DATA=true`;
   - the shop facts below. LINE stays empty until the user provides it: the login button hides itself (`lineConfigured`).
5. **Deploy**, but only after the user confirms the A record resolves (`dig +short demo-tcg-store.amornnan.xyz` = `187.127.106.6`). Otherwise Traefik's certificate request fails.
6. **Post-deploy,** in the web container through Coolify's command, or ask the user to run it in the terminal:
   - `php artisan db:seed --force`;
   - `php artisan scout:sync-index-settings`;
   - the three `scout:import` commands;
   - `php artisan shop:make-staff <user email>`.
7. **Smoke test on the live domain,** with a verifier at the public URL: home, search autocomplete, card page, a PromptPay order (QR shows, demo account name), `/buylist`, `/admin/login` → 2FA set-up. Screenshots.
8. **Then:**
   - edit `.ai/rules/project.md` to "Deployment status: deployed since 2026-11-26";
   - commit that line on `main` as the last direct commit;
   - from then on, branches and PRs;
   - tick #64.

## Shop facts (decided)

All values are clearly demo, so nobody can pay or post anything real.

| Key | Value |
|---|---|
| `APP_NAME` | `Kitsune Card House 狐` |
| `MAIL_FROM_NAME` | `Kitsune Card House` |
| `SHOP_PROMPTPAY_ID` | `0000000000` (valid format, registered to nobody; banking apps reject it) |
| `SHOP_BANK_NAME` | `ธนาคารสาธิต (DEMO)` |
| `SHOP_BANK_ACCOUNT_NAME` | `Kitsune Card House (DEMO)` |
| `SHOP_BANK_ACCOUNT_NUMBER` | `000-0-00000-0` |
| `SHOP_BUYLIST_ADDRESS` | `Kitsune Card House (ร้านสาธิต)` / `ที่อยู่นี้ใช้สำหรับการสาธิตเท่านั้น` / `กรุณาอย่าส่งพัสดุจริง` |
| `SHOP_LINE_OA_URL` | empty until the user's OA exists |
| Shipping rates | the existing defaults (Flash ฿40, Kerry ฿50, J&T ฿40, Thai Post ฿35, protection ฿30, graded insurance ฿200) |
| `BUYLIST_CREDIT_BONUS_BP` | 1500 |
| `SHOP_SAMPLE_PRICE_HISTORY` | `true` (the demo price history is sample data) |
| `SHOP_TIMEZONE` | `Asia/Bangkok` |
| `MAIL_FROM_ADDRESS` | `noreply-demo-tcg-store@amornnan.xyz` (user's choice; `amornnan.xyz` is verified in Resend, SMTP port 587 tested) |
