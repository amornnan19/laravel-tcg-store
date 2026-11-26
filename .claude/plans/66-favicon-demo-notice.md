# Favicon and a "this is a demo" notice

> **สรุป (ไทย)**
> - **Favicon:** ใช้หน้าจิ้งจอกเรขาคณิตสีทอง accent บนพื้นมืดของร้าน เป็นไฟล์ SVG มีไฟล์ PNG สำหรับมือถือและ `favicon.ico` ใหม่ และใช้ไอคอนเดียวกันในหน้า admin
> - **Modal แจ้งว่าเป็นเว็บสาธิต:**
>   - ขึ้นครั้งแรกใน session ตอนเปิดหน้าแรก เปิดหรือปิดได้ด้วย env `SHOP_DEMO_NOTICE` ค่าเริ่มต้นปิด บนเว็บ demo จะเปิด
>   - ปิดได้ 3 ทาง: ปุ่มทองปุ่มเดียว "เข้าใจแล้ว เข้าชมร้าน", ปุ่ม Esc หรือกดพื้นหลัง
>   - ระบบจำไว้ใน session ฝั่ง server จึงไม่กระพริบและไม่ขึ้นซ้ำ
> - **ขั้นตอน:** ทำบน branch แล้วเปิด PR ตามกฎหลัง deploy ให้ reviewer ตรวจโค้ดและดีไซน์พร้อมกัน merge แล้ว CI จะ deploy ให้เอง

## Context

The shop is live at https://demo-tcg-store.amornnan.xyz. The user wants two things:
- a favicon, since there is only Laravel's default `public/favicon.ico`;
- a notice on the first home-page visit of a session, saying that this is a demo site and not a real shop.

Since 2026-11-26 the project is deployed, so all work goes on a branch and lands by PR (`.ai/rules/project.md` § Branching). A merge to `main` deploys through CI (#65).

## Rules in force

- every rule in `.ai/rules/project.md`, especially:
  - one gold button per screen;
  - focus-visible rings;
  - a soft-disabled control must answer;
  - Thai copy in `lang/th`;
  - design tokens only (no hex in Blade);
  - reduced motion;
  - phone width ≤ 390, no overflow;
- the dialog pattern already used in `resources/views/graded.blade.php:86`: `x-show`, `x-cloak`, `x-trap.noscroll`, `role="dialog"`, `aria-modal`;
- the brand mark in the header (`components/layouts/storefront.blade.php`, the `◆` beside `config('app.name')`).

Load the skills `tailwindcss-development` and `livewire-development`.

## Decisions

### Favicon

- **The mark:** a geometric fox head (kitsune), drawn as flat polygons in `--color-accent` `#E7B64E` with an inner face in `--color-accent-ink` `#121318`, on a rounded-square ground in `--color-bg` `#0B0C10`. That is two ear triangles and a pointed muzzle in a symmetric, minimal silhouette. It must read at 16×16, so no fine detail and no text.
  - These hex values are allowed here, because a favicon file is not Blade. Note their token names in an SVG comment.
- **Files in `public/`:**
  - `favicon.svg`: the master, viewBox 0 0 32 32;
  - `favicon.ico` replaced with 16, 32 and 48 sizes;
  - `apple-touch-icon.png`: 180×180, full-bleed `bg` ground, no transparency;
  - `icon-512.png`.
  - Generate the PNGs and the ICO from the SVG with a one-off script in the scratchpad (rsvg-convert, ImageMagick or PHP GD + Imagick, whichever exists locally). Do not add a dependency or commit the script. If no raster tool exists, draw the PNGs with PHP GD from the same polygon coordinates.
- **`<head>` of `components/layouts/storefront.blade.php`, plus the auth layout and every other standalone layout that renders `<head>`** (grep for `<head>`):
  - `<link rel="icon" href="/favicon.svg" type="image/svg+xml">`
  - `<link rel="icon" href="/favicon.ico" sizes="any">`
  - `<link rel="apple-touch-icon" href="/apple-touch-icon.png">`
  - `<meta name="theme-color" content="#0B0C10">`
- **Filament panel:** `->favicon(asset('favicon.svg'))` in the panel provider.

### Demo notice

- **Config:** `config/shop.php` gets `'demo_notice' => (bool) env('SHOP_DEMO_NOTICE', false)`. Add the key to `.env.example`, and as `${SHOP_DEMO_NOTICE:-false}` to the app services in `compose.production.yaml`. The main session sets it to `true` on Coolify.
- **When it shows:** on `GET /` (`HomeController`) only, when `config('shop.demo_notice')` is true and `session('demo_notice_seen')` is not set. The controller does `session()->put('demo_notice_seen', true)` and passes `showDemoNotice = true` to the view. Later visits in the same session never show it.
  - It is server-side, so there is no flash on the second load and no localStorage.
  - It is not shown on other pages: the user asked for the home page.
- **Component:** `resources/views/components/demo-notice.blade.php`, rendered by `home.blade.php` when `$showDemoNotice`. It is an Alpine dialog, `x-data="{ open: true }"`, following the graded pattern:
  - `x-trap.noscroll="open"`, `role="dialog"`, `aria-modal="true"`, `aria-labelledby` and `aria-describedby`;
  - Esc closes it (`x-on:keydown.escape.window`), and so does a click on the backdrop;
  - initial focus goes to the confirm button;
  - entrance is a 160 ms fade and scale on `ease-ui`, with `motion-reduce:transition-none`;
  - the backdrop is `bg-bg/80` with a light blur.
  - Panel:
    - `max-w-md`, `rounded-card`, `border border-line`, `bg-surface`, `p-6`;
    - on a phone, a bottom sheet (`max-lg:` anchored to the bottom with `rounded-t-card`, full width, `pb-[env(safe-area-inset-bottom)]`); centred on desktop.
- **Content** (lang keys `storefront.demo_notice.*`), in this order:
  1. **Badge:** a small `x-` tag in `text-accent` with an accent border, reading "เว็บไซต์สาธิต · DEMO".
  2. **Fox mark:** the favicon at 40 px, `aria-hidden`.
  3. **Heading** (`font-display text-h3`): "ร้านนี้เป็นร้านสาธิต ไม่ใช่ร้านค้าจริง".
  4. **Lead** (`text-muted`): "Kitsune Card House สร้างขึ้นเพื่อแสดงตัวอย่างระบบร้านการ์ดเท่านั้น ไม่มีการขาย ส่งของ หรือรับเงินจริง". Use `config('app.name')` for the name.
  5. **Three short rows,** each with a small stroke icon and `text-small`:
     - "อย่าโอนเงินหรือสแกน QR ชำระเงิน บัญชีและพร้อมเพย์ในเว็บนี้เป็นของสาธิต"
     - "อย่ากรอกข้อมูลส่วนตัวจริง ข้อมูลอาจถูกล้างได้ทุกเมื่อ"
     - "รูปการ์ดเป็นภาพแทน ราคาและสต็อกเป็นข้อมูลตัวอย่าง"
  6. **The single gold button,** full width: "เข้าใจแล้ว เข้าชมร้าน". It closes the dialog.
  7. **Secondary link,** `text-small text-muted`, underlined: "ดูซอร์สโค้ดบน GitHub". It points to `https://github.com/amornnan19/laravel-tcg-store` (`target="_blank" rel="noopener"`). The repo URL comes from config: `shop.source_url`, env `SHOP_SOURCE_URL` with that default. Hide the link when the value is empty.

### Tests

- **Home and the demo notice** (`tests/Feature/DemoNoticeTest.php`):
  - With `demo_notice` on, the first `GET /` contains the dialog and its heading. A second `GET /` in the same session does not.
  - With the flag off, it never shows.
  - Another page, such as `/search`, never shows it.
- **Favicon:** every layout's HTML includes `/favicon.svg`, for the storefront page and the login page. Those two are enough.

**Out of scope:** a persistent demo banner, other pages, and translations other than Thai.

## Steps

1. **Implementer** works on branch `feat/favicon-demo-notice` and does not commit.
   - Done when the new tests, the full suite, Pint and `npm run build` all pass.
   - Its report includes the generated file sizes and how the icons were rasterised.
2. **One `reviewer` call** does both the code review and the design review (the `design-review` skill) on an isolated copy:
   - `php artisan serve --no-reload` with its own DB copy, `APP_URL` and `SHOP_DEMO_NOTICE=true`;
   - judge the modal at 1440 and at 390, the favicon at 16 and 32 px (render the SVG), and the home page behind the modal;
   - check focus trap, Esc and the backdrop, run 3×.
3. **Fix findings,** then commit on the branch, open a PR, and wait for CI to go green. Merge, and CI deploys.
4. **Main session:**
   - set `SHOP_DEMO_NOTICE=true` on the Coolify app before the merge, so the deploy picks it up;
   - after the deploy, check live that the first visit shows the modal, a reload does not, and `/favicon.svg` returns 200.
