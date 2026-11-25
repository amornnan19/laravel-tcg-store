# M7 · Deck-list importer

> **สรุป (ไทย):** แบ่งเป็น 2 ขั้น ทำทีละขั้น
>
> **A — อ่านลิสต์และแบ่งสามกลุ่ม:** หน้า `/deck` เลือกเกมแล้ววางเด็คลิสต์ ระบบอ่านทุกบรรทัด รองรับทั้ง `4x OP05-119 Luffy`, `4 OP05-119`, `4x Luffy`, `OP05-119 x4` และรูปแบบของ PTCG Live
> การจับคู่ไล่ตามลำดับ: เลขเซ็ตกับเลขการ์ด → เลขการ์ดเปล่าเมื่อมีบรรทัดประกาศเซ็ต → ชื่อตรงตัวในสามภาษา → ชื่อใกล้เคียงผ่าน Meilisearch ที่คะแนนเกินเกณฑ์
> บรรทัดที่อ่านไม่ออกจะแสดงพร้อมข้อความเดิมเสมอ ไม่ทิ้งเงียบ ผลแบ่งเป็น มีครบ / มีไม่พอ / ไม่มี มีสรุปยอดอยู่บนสุด
> ค่าเริ่มต้นเลือกแบบที่ถูกที่สุดที่มีของพอ และมี dropdown ให้เปลี่ยนแบบได้
>
> **B — เพิ่มทั้งหมดและบันทึกลิสต์:** ปุ่มเดียวเพิ่มทั้งลิสต์ลงตะกร้า ถ้าจองไม่ได้แม้แต่ใบเดียว จะไม่มีอะไรเข้าตะกร้าเลยและบอกว่าใบไหนติด
> ตั้งแจ้งเตือนของเข้าทั้งก้อนสำหรับใบที่ไม่มี และลูกค้าที่ล็อกอินบันทึกลิสต์ไว้เช็กอีกครั้งทีหลังได้
> มีลิงก์ "วางเด็ค" ที่หัวเว็บและในตะกร้าว่าง
>
> **ไม่ทำในรอบนี้:** หน้า Saved deck lists ใน admin, การแบ่งหนึ่งบรรทัดเป็นหลายสภาพ, การ์ดเกรด/สินค้ากล่อง (M8)

## Context

M6 shipped accounts, alerts and `SetRestockAlerts(User, array $variantIds)`. M7 in `docs/README.md` is "the parser,
the three buckets, add-all". Behaviour is in `docs/accounts-line-buylist.md` § "The deck-list importer", the
`saved_deck_lists` table is in `docs/data-model.md`, the route `/deck` (`deck.import`) and the `Storefront\DeckImport`
component are in `docs/storefront.md`, and `docs/architecture.md` lists the test "a line with more quantity than stock
lands in the partial bucket". Copy comes from `Flows.dc.html` (วางเด็คลิสต์ · มีครบ · มีไม่พอ · ไม่มี · เพิ่มทั้งหมด ·
ตั้งแจ้งเตือนทั้งก้อน) and `States.dc.html` (the empty cart's "วางเด็คลิสต์").

There is no M06 artboard in `storage/canvas-ref/project/`. The page is built from the existing components and tokens
(cart panel, variant table, `docs/design-tokens.md`), in the same way the account page was. The user delegated every
decision; they are below.

The existing code this reuses:
- `App\Search\QueryParser` (scoped), whose `parse()` returns a `ParsedQuery` with `kind` Printing / Set / Text and
  `printingIds` / `setIds`.
- `Printing::scopeSearchVisible`, `withCheapestVariant`, `stockSummary()`.
- `Variant::scopePurchasable`, `scopeWithReservedQty`, `availableQty()`, `isAvailable()`.
- `AddToCart` (it locks the variant first and sums onto an existing line), `CurrentCart::findOrCreate`.
- `SetRestockAlerts`, `CustomerLogin`.
- `Printing::search()`, which goes through Scout and Meilisearch; `phpunit.xml` sets `SCOUT_DRIVER=null`.

## Rules in force

Every rule in `.ai/rules/project.md` applies. These matter most here:
- Lock the variant first, and every path that creates a hold applies the purchasability check.
- One gold primary button per screen.
- An action that belongs to a row sits inside that row.
- A property bound with `wire:model.live` is never rewritten.
- The Livewire `unset()` rule.
- Search hits are hydrated through `searchVisible()`.
- Available stock comes from the scope or `availableQty()`, never `qty`.
- Thai copy lives only in `lang/th/`.
- Money is formatted by `<x-price>` / `Money`.
- `focus-visible`, `aria-disabled`, and the Tailwind same-property rule.
- `json` columns are touched only through casts.
- The new rules from M6 (queued jobs, `DB::afterCommit`).

Before writing, use Boost `search-docs` for: Scout raw search, Meilisearch `showRankingScore`, Livewire 4 textarea
and `wire:model` modifiers, and Laravel `DB::transaction` nesting / savepoints. Load the skills `livewire-development`,
`tailwindcss-development`, `laravel-best-practices` and `testing-best-practices`. Run Pint. The implementer does not
commit.

## Decisions (do not reopen)

**Line formats.**
- Parsing is line by line.
- Blank lines are skipped.
- A quantity is a leading `N`, `Nx` or `N x`, or a trailing `xN` / `x N`. With neither, the quantity is 1.
- Quantities are capped at 99 per line; a larger number is read as 99, and the line says so.
- Section headers that deck builders export (`Pokémon: 12`, `Trainer: 30`, `Energy: 18`, `Leader`, `Main Deck`,
  `Side Deck`, `Extra Deck`, `Don!! Deck`, lines starting `//` or `#`) come back as `ignored` with their text, shown
  in a quiet "ไม่ได้อ่าน" list. They are never dropped silently.
- A line `Set: OP05` / `เซ็ต: OP05` declares the set for the lines after it. It is shown as `directive`.

**Matching order, first hit wins** (docs):
1. A set code plus a card number. Try `QueryParser::parse()` on the first 1–3 tokens of the line body (after the
   quantity is removed), then on the last two tokens (the PTCG Live form `Charizard ex OBF 125`). A `Printing` result
   matches.
2. A bare card number (`4 119`) when a set has been declared: the printings of the declared set with that number.
3. An exact card name, case-insensitive and whitespace-collapsed, against `name_en`, `name_ja` and `name_th` of the
   chosen game's cards. A batched `whereIn` on `lower(...)` uses a normalised name map built once per parse.
4. A fuzzy name match through Scout. It is accepted only at Meilisearch `_rankingScore >= config('deck.fuzzy_min_score')`,
   which defaults to 0.9. It sits behind an interface `App\Search\FuzzyCardMatcher` with a Meilisearch implementation
   that returns `null` on any engine error, and tests bind a fake. A fuzzy match is flagged and shows
   "จับคู่แบบใกล้เคียง". Fuzzy runs only for lines still unmatched, at most 30 of them.

**What a match yields.**
- A set of candidate printings: every `searchVisible` printing of that code, or of that card in the chosen game,
  filtered by the optional language preference.
- The candidate variants are their `purchasable()` variants, loaded `withReservedQty`.

**Buckets, one variant per line.**
- **มีครบ:** some candidate variant has `availableQty >= requested`. The default is the cheapest of those.
- **มีไม่พอ:** none covers it, but the best has `0 < available`. The default is the variant with the most available,
  cheapest on a tie. The row offers "เอาเท่าที่มี (:n ใบ)", which is on by default.
- **ไม่มี:** no candidate is available, or the line is unmatched. A matched line whose candidates have no
  purchasable variant at all reads "ร้านยังไม่มีการ์ดนี้".
- Splitting one line across two conditions is rejected: it is too clever for a first version, and the dropdown
  covers the override.
- The dropdown lists every available candidate variant: language · condition · finish · price · "มี N". Picking
  another recalculates that line's bucket.

**The game.** It is chosen from radio chips of the active games and is required. The first active game is the
default, or the game of the card most recently added to the cart, if cheap. Use the first active game.

**The language preference** is an optional chip group: ทุกภาษา (default) / JP / EN / TH, using `App\Enums\Language`.

**Limits.**
- The raw text is at most 10,000 characters and at most 200 non-blank lines. Over that, show the validation error
  `deck.errors.too_long`.
- Parsing must stay bounded in queries. A test pins fewer than 40 queries for a 48-line list.

**When parsing runs.** On the "ตรวจลิสต์" button, and on the textarea's `paste` event (Alpine `x-on:paste` →
`$wire.check()` after `$nextTick`). Not on every keystroke. The raw text is bound with plain `wire:model`.

**Add-all** (Step B) is one action, `App\Actions\AddDeckToCart`:
- One `DB::transaction` that locks every distinct variant with `lockForUpdate()`, ordered by id, before any other
  read.
- Then it calls `AddToCart` once per distinct variant, with the quantities of lines on the same variant summed. Each
  call is a nested transaction.
- It catches `InsufficientStock` / `NotPurchasable` per variant to collect every failure.
- If any failed, it throws `App\Exceptions\DeckNotReserved` with the failed variant ids **from inside the closure**,
  so the whole transaction rolls back and nothing is held.
- Lines in the partial bucket add their available quantity when "เอาเท่าที่มี" is on, and are skipped otherwise.
  Lines in the missing bucket are never added.
- On failure, the component re-parses (stock moved) and shows "เพิ่มไม่สำเร็จ ยังไม่มีอะไรเข้าตะกร้า ของบางใบเพิ่งหมด
  เช็กรายการใหม่แล้ว", with the failed rows marked.

**Bulk restock alert** (Step B).
- "ตั้งแจ้งเตือนทั้ง :n ใบ" calls `SetRestockAlerts` with, for each missing-but-matched line, the purchasable
  candidate variants (language preference applied).
- Guests are sent to login with the intended URL. The parsed text is preserved in the session under `deck.pending`
  (raw text, game, language) and restored on return.

**Saved lists** (Step B).
- Signed-in users only.
- Table `saved_deck_lists`: `id`, `user_id` (fk cascade), `game_id` (fk), `name` (string 100), `raw_text` (text, kept
  exactly as pasted), `parsed` (json: the per-line match result without stock, i.e. kind, qty, printing ids, text),
  `timestamps`.
- "บันทึกลิสต์" takes an optional name (default "เด็ค :game · :date").
- The account page gets a section "เด็คที่บันทึกไว้" with "เช็กอีกครั้ง" (→ `/deck?list={id}`, owner only, else 404,
  which loads the raw text and game and parses at once) and a delete.

**Navigation** (Step B).
- The desktop header gets a "วางเด็ค" link → `deck.import`.
- The empty cart state gets "วางเด็คลิสต์" as its primary action, with copy from `States.dc.html`: "มีเด็คลิสต์อยู่แล้ว
  หรือเปล่า แปะมาเลย ระบบจะเช็กให้ทั้งใบ". Keep "ค้นหาการ์ด" secondary.
- If `home.blade.php` has a tools entry for the deck list, point it at `deck.import`.

**Out of scope:** an admin Saved deck lists resource, line splitting across variants, graded or sealed matches, and
editing a line's quantity in the result (the customer edits the text and checks again).

## Step A — parser, matching, the three buckets (issue #47)

**Code**
- `config/deck.php`: `max_chars` 10000, `max_lines` 200, `max_qty` 99, `fuzzy_min_score` 0.9, `fuzzy_max_lines` 30.
- `App\Enums\DeckLineKind`: `matched`, `unmatched`, `ignored`, `directive`.
- `App\Enums\DeckBucket`: `full`, `partial`, `missing`, with `label()`.
- `App\Actions\ParseDeckList::handle(Game $game, string $raw, ?Language $language = null): DeckParse`.
  - It returns readonly value objects in `app/Support/Deck/`:
    - `DeckParse` with `lines` (`list<DeckLine>`), `counts()` per bucket and `totalSatang()` of what add-all would add.
    - `DeckLine` with `number` (the line number in the paste), `text` (the original line), `kind`, `qty`,
      `qtyCapped` (bool), `matchedBy` (`code` / `declared_set` / `name` / `fuzzy`), `printingIds`, `variants`
      (the candidate variant ids with their available qty and price), `defaultVariantId` and `bucket`.
  - It is pure reading; it never writes.
- `App\Search\FuzzyCardMatcher` (interface): `match(Game $game, string $name): ?array{card_id:int, score:float}`.
  `App\Search\MeilisearchFuzzyCardMatcher` searches the printings index filtered by game with `showRankingScore`.
  It is bound in `AppServiceProvider`.
- `App\Support\Deck\DeckBuckets::assign(DeckLine $line, ?int $chosenVariantId): DeckLine` is reused when the
  dropdown changes a line.
- Route `GET /deck` → `App\Http\Controllers\Storefront\DeckController`, a view `deck.blade.php` containing
  `<livewire:storefront.deck-import />`.
- **`Storefront\DeckImport` component.**
  - State: `gameId`, `language` (nullable), `raw`, a `#[Locked]` parse snapshot (line number → chosen variant id and
    the partial toggle), and `checked` (bool).
  - The parse result is a `#[Computed]` recomputed from `raw` / game / language plus the chosen-variant map.
  - Actions: `check()`, `chooseVariant(int $line, int $variantId)` (refused unless the id is among that line's
    candidates), `togglePartial(int $line)` and `clear()`.
- **Layout.**
  - Top: the game chips, the language chips, and the textarea (`rows` 10, monospace token `font-mono` if the tokens
    have one, else the body font) with a placeholder showing the five formats. Then "ตรวจลิสต์", the gold primary
    until the list is checked; after a check, "เพิ่มทั้งหมด" in Step B becomes the one gold button, and "ตรวจลิสต์"
    turns secondary.
  - Then the summary card: "มีครบ :full · มีไม่พอ :partial · ไม่มี :missing", "รวม :cards ใบ · :total". It reads as
    the first thing after a check: the heading is focused with `$nextTick`, and the region carries `aria-live="polite"`.
  - Then three sections in bucket order. Each row shows:
    - the pasted text in muted;
    - the matched card name, code and number;
    - a `<x-condition-badge>` and `<x-language-badge>` of the chosen variant;
    - `<x-price>` × qty;
    - the dropdown (a native `<select>` styled like the checkout fields);
    - "มี :n" or "ต้องการ :want · มี :have", plus "เอาเท่าที่มี" as a `<x-checkbox>` for partial rows;
    - "จับคู่แบบใกล้เคียง" for a fuzzy match;
    - "อ่านบรรทัดนี้ไม่ออก" for an unmatched line.
  - Then the quiet "ไม่ได้อ่าน" list for ignored and directive lines.
  - The empty state before any check: `<x-empty-state>` with "วางเด็คลิสต์จากแอปสร้างเด็คได้เลย ไม่ต้องจัดรูปแบบ".
- **Copy.** All of it goes under `storefront.deck.*` in `lang/th/storefront.php`: the title "วางเด็คลิสต์", labels,
  placeholder, buckets, errors (`too_long` "ลิสต์ยาวเกินไป วางได้ไม่เกิน :lines บรรทัด", `empty` "วางเด็คลิสต์ก่อน",
  `game` "เลือกเกม"), "ไม่ได้อ่าน", "ใกล้เคียง", "อ่านบรรทัดนี้ไม่ออก", "ร้านยังไม่มีการ์ดนี้", "จำนวนเกิน :max
  ใบ อ่านเป็น :max". Bucket labels go in `lang/th/enums.php`.

**Tests**
- **Every format.** Each of the five docs formats, a PTCG Live line, a section header (`ignored`), a `Set:` directive
  with bare numbers, a blank line skipped, a qty cap, and an unmatched line that keeps its original text and line
  number.
- **Matching order.** A code wins over a name; name in each of the three languages; a fuzzy match through the fake
  above the threshold, refused below it, and an engine error falls back to unmatched without an exception. Another
  game's card never matches, and a hidden printing (trashed card or inactive game) never matches.
- **Buckets.** Full picks the cheapest covering variant. **Partial when the quantity exceeds stock** (the docs test).
  Missing when everything is reserved by other carts. "ร้านยังไม่มีการ์ดนี้" when there is no priced variant. The
  language preference narrows the candidates.
- **The component.** `chooseVariant` recalculates the bucket and refuses a variant that is not a candidate;
  `togglePartial`; the validation errors; the query bound (fewer than 40 for 48 lines, through `DB::listen`).
- Route `/deck` renders for guests.

## Step B — add-all, bulk restock, saved lists, navigation (issue #48)

**Code**
- `AddDeckToCart::handle(Cart $cart, array<int,int> $variantQty): void`, as decided above, with
  `App\Exceptions\DeckNotReserved` (`public array $variantIds`).
- `DeckImport::addAll(AddDeckToCart, CurrentCart)`.
  - It builds the variant → qty map from the current parse: full lines take `qty`; partial lines take their available
    quantity when the toggle is on.
  - Then it calls the action. On success it redirects to `cart` with the status "เพิ่ม :n ใบลงตะกร้าแล้ว · ล็อกไว้
    :minutes นาที".
  - On `DeckNotReserved` it unsets the parse, re-checks, marks those rows, and shows the error above.
  - The button reads "เพิ่มทั้งหมดลงตะกร้า (:n ใบ · :total)" and is `aria-disabled` when nothing can be added.
- `DeckImport::alertMissing(SetRestockAlerts)`. It is guest → login with `deck.pending` stored; `AlertRefused` is shown
  inline in the missing section. On success it shows "ตั้งแจ้งเตือนแล้ว :n ใบ". The button reads
  "ตั้งแจ้งเตือนทั้ง :n ใบ" (secondary).
  - `mount()` restores `deck.pending` (pull) for a signed-in user.
- Migration, model `SavedDeckList` (`$fillable`, `parsed` cast `array`), factory, `User::savedDeckLists()`.
  - `DeckImport::save()` (signed in; name ≤ 100) stores `raw_text` unchanged and the `parsed` summary.
  - `mount(?int list)` reads `request('list')`; an owner-only `SavedDeckList` loads raw and game, then checks.
    Anything else is 404.
  - The account page section "เด็คที่บันทึกไว้" lists name, game, date, "เช็กอีกครั้ง", and delete through
    `DELETE /account/decks/{savedDeckList}` (owner only, 404).
- Navigation and the empty cart state, as decided above.
- Copy under `storefront.deck.*` and `storefront.account.decks.*`.

**Tests**
- **Add-all success.** Holds for every line; two lines on the same variant are summed; partial lines with the toggle
  on add the available quantity, and are skipped when it is off; missing lines are never added.
- **All-or-nothing.** Make one variant's stock vanish between the parse and add-all (another cart reserves it).
  Nothing is held and no cart line is created, even for the lines that would have fit. The failed variant id is
  reported.
- **Lock-first query order** (the `DB::listen` pattern from `CartReservationTest`).
- **Unpurchasable variant.** A variant that became unpurchasable (price cleared) also fails the whole add.
- **Bulk alert.** One call with many ids; a guest is redirected and the list is restored after login; a no-channel
  user gets the refusal message.
- **Saved lists.** Save keeps `raw_text` byte for byte (with tabs, trailing spaces and CRLF); re-check parses against
  current stock; owner only, so someone else's id gives 404 and deleting someone else's gives 404.
- The header link and the empty cart link render.

## Order and done

A → B, one implementer run each.
1. **Open the issues first.** Open two `enhancement` issues in milestone "M7 · Deck-list importer": "Deck-list parser
   and the three buckets" and "Add a deck list to the cart, bulk restock alerts and saved lists".
2. **After each step:**
   - `verifier`: the full `php artisan test --compact`, Pint, `npm run build`, `migrate:fresh --seed`, and a browser run
     at 1440×900 and 390×844 with viewport screenshots into `storage/screens/m7-<step>/`.
     - Step A: the empty page, a pasted mixed list from seeded cards showing all three buckets plus an unmatched and an
       ignored line, the dropdown changing a bucket, and the too-long error.
     - Step B: add-all landing on the cart with every line held, the all-or-nothing failure (made with tinker), the
       bulk alert, saving, the account section, and re-checking.
   - `reviewer` on both steps: stock holds and a public endpoint.
   - `designer` on both steps.
3. **Commit.** Fix the findings, then commit to `main` per step: `feat(deck): …`. A bug in M7's scope gets its own
   `bug` issue in the milestone.
4. **M7 is done when** both issues are closed and, on a seeded local app, a visitor pastes a 40+ line list built from
   seeded cards, sees the three buckets with the summary first, overrides one line, adds everything in one click with
   every line held, sets restock alerts for the missing ones in one click, and a signed-in customer saves the list and
   re-checks it from the account page.
