<?php

namespace App\Livewire\Storefront;

use App\Actions\AddDeckToCart;
use App\Actions\ParseDeckList;
use App\Actions\SetRestockAlerts;
use App\Enums\DeckBucket;
use App\Enums\DeckLineKind;
use App\Enums\Language;
use App\Exceptions\AlertRefused;
use App\Exceptions\DeckNotReserved;
use App\Models\Game;
use App\Models\SavedDeckList;
use App\Support\CurrentCart;
use App\Support\Deck\DeckBuckets;
use App\Support\Deck\DeckLine;
use App\Support\Deck\DeckMatch;
use App\Support\Deck\DeckParse;
use App\Support\Deck\DeckVariant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The deck-list importer page: the customer picks a game, pastes a list and sees what the shop can fill, in three
 * buckets. Nothing is saved or reserved here; every parse reads the catalogue as it is now.
 *
 * Matching is the expensive half of parsing (set codes, names, up to 30 engine searches). It runs once per check, or
 * once when the game changes, and only those actions (`check()`, `selectGame()`) are rate limited. Its outcome is kept
 * in locked state (`matched`); every later request only prices it again from the catalogue (`parse`) and lays the
 * customer's picks over that (`result`). The game and language change only through actions, never by a client write.
 *
 * Adding to the cart, the bulk restock alert and saving act on the shown result, never on what the browser claims: the
 * variants and quantities are recomputed on the server, and the total the customer saw must still be the total.
 *
 * `raw` is what the textarea holds. `check()` copies it to `checkedRaw`, and that copy is what gets parsed, so text
 * typed after a check does not change the result until the next check.
 */
class DeckImport extends Component
{
    private const CHECKS_PER_MINUTE = 20;

    /**
     * The priced list per language for this request only: private, so it is never sent to the browser or kept.
     *
     * @var array<string, ?DeckParse>
     */
    private array $pricedByLanguage = [];

    #[Locked]
    public ?int $gameId = null;

    #[Locked]
    public ?Language $language = null;

    public string $raw = '';

    /**
     * The text the shown result was parsed from.
     */
    #[Locked]
    public string $checkedRaw = '';

    /**
     * Which card each line of `checkedRaw` names (a `DeckMatch`), without stock.
     *
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $matched = [];

    #[Locked]
    public bool $checked = false;

    /**
     * Whether the last check was refused, so the shown result is older than what the customer asked for.
     */
    #[Locked]
    public bool $checkFailed = false;

    /**
     * What the customer changed after the parse, `line number => ['variant' => ?int, 'partial' => bool]`. Only ids,
     * ints and booleans.
     *
     * @var array<int, array{variant?: ?int, partial?: bool}>
     */
    #[Locked]
    public array $choices = [];

    /**
     * What the last add-all said when it added nothing, or what the last save said.
     */
    #[Locked]
    public ?string $addNotice = null;

    /**
     * Whether `addNotice` is a failure (nothing could be held) rather than a prompt to look at the list again.
     */
    #[Locked]
    public bool $addFailed = false;

    /**
     * Variant ids the last add-all could not hold, so their rows can say so.
     *
     * @var list<int>
     */
    #[Locked]
    public array $failedVariants = [];

    /**
     * What the last bulk restock alert said, and whether it was a success.
     */
    #[Locked]
    public ?string $alertNotice = null;

    #[Locked]
    public bool $alertSet = false;

    #[Locked]
    public ?string $saveNotice = null;

    /**
     * The optional name for "save list".
     */
    public string $saveName = '';

    /**
     * Opens a saved list (`?list=`) and checks it at once; else restores the list a guest was holding when they were sent
     * to log in. A saved list that is not the signed-in customer's is a 404, as if it did not exist.
     */
    public function mount(): void
    {
        $this->gameId = $this->games->first()?->id;

        $list = request()->query('list');

        if ($list !== null) {
            $saved = is_string($list) && ctype_digit($list) ? SavedDeckList::query()->find((int) $list) : null;

            if ($saved === null || $saved->user_id !== auth()->id()) {
                throw new NotFoundHttpException;
            }

            $this->restore($saved->raw_text, $saved->game_id, null);

            return;
        }

        // A list held across a login is flashed by `CustomerLogin` for this one request. On a guest page whatever is left
        // is discarded, so a shared browser never hands one customer's list to the next.
        if (auth()->guest()) {
            session()->forget(['deck.pending', 'deck.restore']);

            return;
        }

        // Only what `CustomerLogin` moved to `deck.restore`, once. `deck.pending` is a guest's leftover (a panel login
        // never converts it), and a payload older than 15 minutes belongs to whoever sat there before.
        $pending = session()->pull('deck.restore');
        session()->forget('deck.pending');

        if (is_array($pending) && (int) ($pending['at'] ?? 0) >= now()->subMinutes(15)->timestamp) {
            $this->restore((string) ($pending['raw'] ?? ''), (int) ($pending['game'] ?? 0), Language::tryFrom((string) ($pending['language'] ?? '')));

            // The guest pressed the bulk alert before logging in: finish it now and bring its result into view.
            if (($pending['alert'] ?? false) === true && $this->checked) {
                $this->alertMissing(app(SetRestockAlerts::class));
                $this->dispatch('deck-alerted');
            }
        }
    }

    private function restore(string $raw, int $gameId, ?Language $language): void
    {
        $this->language = $language;
        $this->raw = $raw;

        // A game the shop has closed: the list is kept in the box, but it is not read against some other game.
        if (! $this->games->contains('id', $gameId)) {
            $this->addError('gameId', __('storefront.deck.list_game_closed'));

            return;
        }

        $this->gameId = $gameId;

        try {
            $this->check();
        } catch (ValidationException) {
            // The errors are in the bag; the text stays in the box for the customer to fix.
        }
    }

    /**
     * Matches what is in the textarea now, and starts from the defaults.
     */
    public function check(): void
    {
        if ($this->throttled()) {
            $this->checkFailed = true;

            return;
        }

        try {
            $this->validate();
        } catch (ValidationException $exception) {
            $this->checkFailed = true;

            throw $exception;
        }

        $this->checkedRaw = $this->raw;
        $this->checked = true;
        $this->checkFailed = false;
        $this->choices = [];
        $this->clearNotices();

        $this->rematch();

        $this->dispatch('deck-checked');
    }

    /**
     * Another game, from the chips. The checked list is matched against it again, so this counts against the same
     * rate limit as a check.
     */
    public function selectGame(int $gameId): void
    {
        if (! $this->games->contains('id', $gameId)) {
            $this->addError('gameId', __('storefront.deck.errors.game'));

            return;
        }

        if ($this->checked && $this->throttled()) {
            return;
        }

        $this->gameId = $gameId;
        $this->choices = [];
        $this->clearNotices();
        $this->resetErrorBag('gameId');

        if ($this->checked) {
            $this->rematch();
        }
    }

    /**
     * Another language preference. It only narrows what is priced, so no matching runs and no rate limit applies.
     */
    public function selectLanguage(?string $language): void
    {
        $chosen = $language === null ? null : Language::tryFrom($language);

        if ($language !== null && $chosen === null) {
            return;
        }

        $this->language = $chosen;
        $this->choices = [];
        $this->clearNotices();

        unset($this->parse, $this->result);
    }

    /**
     * Whether the matching rate limit is used up; counts this attempt when it is not. Says how long to wait when it is.
     */
    private function throttled(): bool
    {
        $key = self::throttleKey((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::CHECKS_PER_MINUTE)) {
            $this->addError('raw', __('storefront.deck.errors.throttled', ['seconds' => RateLimiter::availableIn($key)]));

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    /**
     * The rate-limit key of a client: its IPv4 address, or for IPv6 its /64 prefix, since one client owns a whole /64.
     * `$prefix` names the limit, so another page's checks (the buylist's) count on their own.
     */
    public static function throttleKey(string $ip, string $prefix = 'deck-check'): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? (string) inet_pton($ip) : '';

        // An IPv4 client seen on a dual-stack socket arrives as ::ffff:a.b.c.d; its /64 would be all zeros and shared by every
        // IPv4 visitor, so it is keyed by the IPv4 address it carries.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return $prefix.':'.inet_ntop(substr($packed, 12));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ip = bin2hex(substr((string) inet_pton($ip), 0, 8));
        }

        return $prefix.':'.$ip;
    }

    private function rematch(): void
    {
        $this->pricedByLanguage = [];

        $game = $this->games->firstWhere('id', $this->gameId);

        $this->matched = $game === null
            ? []
            : app(ParseDeckList::class)->match($game, $this->checkedRaw)->toArray();

        unset($this->parse, $this->result);
    }

    /**
     * Fills a line from another of its candidate variants. An id that is not one of the line's candidates is the
     * client's claim and is ignored.
     */
    public function chooseVariant(int $line, int $variantId): void
    {
        $found = $this->findLine($line);

        if ($this->isStale() || $found === null || ($found->remaining[$variantId] ?? 0) < 1) {
            return;
        }

        $this->choices[$line]['variant'] = $variantId;
        $this->clearNotices();

        unset($this->result);
    }

    /**
     * Flips whether a partial line is added with what is available.
     */
    public function togglePartial(int $line): void
    {
        if ($this->isStale() || $this->findLine($line) === null) {
            return;
        }

        $this->choices[$line]['partial'] = ! ($this->choices[$line]['partial'] ?? true);
        $this->clearNotices();

        unset($this->result);
    }

    public function clear(): void
    {
        $this->pricedByLanguage = [];

        $this->reset('raw', 'checkedRaw', 'matched', 'checked', 'checkFailed', 'choices');
        $this->clearNotices();
        $this->resetErrorBag();

        unset($this->parse, $this->result);
    }

    private function clearNotices(): void
    {
        $this->addNotice = null;
        $this->addFailed = false;
        $this->failedVariants = [];
        $this->alertNotice = null;
        $this->alertSet = false;
        $this->saveNotice = null;
    }

    /**
     * Puts everything the shown result would add in the cart, or nothing. `$shownSignature` fingerprints what the page
     * showed (the game, every variant with its copies, the total): the variants and quantities are recomputed here from
     * the result and never taken from the browser, and if they differ in any way (a pick sold out and another variant
     * stepped in, a price moved) nothing is added and the customer sees the list again as it is now.
     */
    public function addAll(string $shownSignature, AddDeckToCart $addDeckToCart, CurrentCart $currentCart): void
    {
        $game = $this->games->firstWhere('id', $this->gameId);
        $result = $this->result;

        if ($game === null || $result === null) {
            return;
        }

        $this->clearNotices();

        if ($this->isStale()) {
            $this->addNotice = __('storefront.deck.stale');

            return;
        }

        $variantQty = $result->variantQty();

        if ($variantQty === []) {
            $this->addNotice = __('storefront.deck.nothing_to_add');

            return;
        }

        if (! hash_equals($result->signature($game->id), $shownSignature)) {
            $this->addNotice = __('storefront.deck.total_changed');
            $this->reprice();

            return;
        }

        try {
            $addDeckToCart->handle($currentCart->findOrCreate(), $game, $variantQty);
        } catch (DeckNotReserved $failed) {
            $this->failedVariants = $failed->variantIds;
            $this->addFailed = true;
            $this->addNotice = __('storefront.deck.add_failed');
            $this->reprice();

            return;
        }

        session()->flash('status', __('storefront.deck.added', ['n' => array_sum($variantQty), 'minutes' => config('shop.reservation_minutes')]));

        $this->redirectRoute('cart');
    }

    /**
     * Prices the checked list again from the catalogue as it is now.
     */
    private function reprice(): void
    {
        $this->pricedByLanguage = [];

        unset($this->parse, $this->result);
    }

    /**
     * Sets a restock alert on every candidate variant of every line the shop does not have. The variants come from the
     * shown result, never from the browser. A guest is sent to log in and gets the list back afterwards.
     */
    public function alertMissing(SetRestockAlerts $setRestockAlerts): void
    {
        $result = $this->result;

        if ($result === null) {
            return;
        }

        $this->clearNotices();

        if ($this->isStale()) {
            $this->alertNotice = __('storefront.deck.stale');

            return;
        }

        if (auth()->guest()) {
            session()->put('url.intended', route('deck.import'));
            session()->put('deck.pending', ['raw' => $this->checkedRaw, 'game' => $this->gameId, 'language' => $this->language?->value, 'alert' => true, 'at' => now()->timestamp]);

            $this->redirectRoute('login');

            return;
        }

        if (auth()->user()->is_staff) {
            $this->alertNotice = (new AlertRefused('unavailable'))->message();

            return;
        }

        $lines = $this->alertableLines();

        if ($lines === []) {
            return;
        }

        try {
            $variantIds = array_values(array_unique(array_merge(...array_map(fn (DeckLine $line): array => array_keys($line->variants), $lines))));

            if ($setRestockAlerts->handle(auth()->user(), $variantIds) === 0) {
                $this->alertNotice = (new AlertRefused('unavailable'))->message();

                return;
            }
        } catch (AlertRefused $refused) {
            $this->alertNotice = $refused->message();

            return;
        }

        $this->alertSet = true;
        $this->alertNotice = __('storefront.deck.alerts_set', ['n' => count($lines)]);
    }

    /**
     * The matched lines the shop has a purchasable variant of but none in stock. A restock alert fires when a variant
     * goes from nothing to something, so a line missing only because earlier lines took the stock is not one.
     *
     * @return list<DeckLine>
     */
    public function alertableLines(): array
    {
        return array_values(array_filter(
            $this->result?->lines ?? [],
            fn (DeckLine $line): bool => $line->kind === DeckLineKind::Matched && $line->bucket === DeckBucket::Missing && $line->variants !== []
                && array_filter($line->variants, fn (DeckVariant $variant): bool => $variant->availableQty > 0) === [],
        ));
    }

    /**
     * Keeps the checked list, exactly as pasted, for the signed-in customer to check again later. Only a fresh result is
     * saved: `parsed` is what that text matched.
     */
    public function save(): void
    {
        $user = auth()->user();
        $game = $this->games->firstWhere('id', $this->gameId);

        if ($user === null || $game === null || ! $this->checked) {
            return;
        }

        $this->clearNotices();

        if ($this->isStale()) {
            $this->addError('saveName', __('storefront.deck.stale'));

            return;
        }

        $this->validate(['saveName' => ['nullable', 'string', 'max:100']], ['saveName.max' => __('storefront.deck.errors.name_too_long')]);

        // Compared here with `===`, over at most `deck.max_saved` rows: a SQL `where raw_text = ?` is case-insensitive under
        // MySQL's default collation, so `4x luffy` and `4X Luffy` would count as one list.
        $existing = SavedDeckList::query()->select(['id', 'name', 'raw_text'])->where('user_id', $user->id)->where('game_id', $game->id)->get()
            ->first(fn (SavedDeckList $saved): bool => $saved->raw_text === $this->checkedRaw);

        if ($existing !== null) {
            $this->saveNotice = __('storefront.deck.already_saved', ['name' => $existing->name]);

            return;
        }

        if (SavedDeckList::query()->where('user_id', $user->id)->count() >= (int) config('deck.max_saved')) {
            $this->addError('saveName', __('storefront.deck.errors.saved_cap', ['max' => config('deck.max_saved')]));

            return;
        }

        $name = trim($this->saveName);

        SavedDeckList::query()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'name' => $name !== '' ? $name : __('storefront.deck.default_name', [
                'game' => $game->name_en,
                'date' => now(config('shop.timezone'))->locale('th')->translatedFormat('j M Y'),
            ]),
            'raw_text' => $this->checkedRaw,
            'parsed' => SavedDeckList::slim($this->matched),
            'requested_cards' => SavedDeckList::requestedIn($this->matched),
        ]);

        $this->saveName = '';
        $this->saveNotice = __('storefront.deck.saved');
    }

    /**
     * Whether the shown result no longer matches what the customer asked for: the text was edited since the check, or
     * the last check was refused. Picks on a stale result would be picks on a list that is gone.
     */
    public function isStale(): bool
    {
        return $this->checked && ($this->checkFailed || $this->raw !== $this->checkedRaw);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'gameId' => ['required', Rule::exists('games', 'id')->where('is_active', true)],
            'raw' => [
                'required',
                'string',
                'max:'.config('deck.max_chars'),
                function (string $attribute, mixed $value, callable $fail): void {
                    $lines = preg_split('/\R/u', (string) $value) ?: [];

                    // The parser's own notion of blank, so NBSP-only lines do not count against the limit.
                    if (count(array_filter($lines, fn (string $line): bool => preg_match('/^[\s\p{Z}]*$/u', $line) !== 1)) > (int) config('deck.max_lines')) {
                        $fail($this->tooLong());
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'raw.required' => __('storefront.deck.errors.empty'),
            'raw.max' => $this->tooLong(),
            'gameId.required' => __('storefront.deck.errors.game'),
            'gameId.exists' => __('storefront.deck.errors.game'),
        ];
    }

    private function tooLong(): string
    {
        return __('storefront.deck.errors.too_long', ['lines' => config('deck.max_lines')]);
    }

    private function findLine(int $number): ?DeckLine
    {
        foreach ($this->result?->lines ?? [] as $line) {
            if ($line->number === $number) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, Game>
     */
    #[Computed]
    public function games(): Collection
    {
        return Game::query()->where('is_active', true)->orderBy('sort')->get();
    }

    /**
     * The match priced from the catalogue as it is now, with every line at its defaults.
     */
    #[Computed]
    public function parse(): ?DeckParse
    {
        if (! $this->checked) {
            return null;
        }

        // Kept per language for the rest of this request: switching the language back and forth inside one request (up to
        // Livewire's call limit) must not price the whole list again each time.
        return $this->pricedByLanguage[$this->language?->value ?? '*']
            ??= app(ParseDeckList::class)->price(DeckMatch::fromArray($this->matched), $this->language);
    }

    /**
     * The parse with the customer's picks laid over it: what the page shows.
     */
    #[Computed]
    public function result(): ?DeckParse
    {
        $parse = $this->parse;

        if ($parse === null) {
            return null;
        }

        return new DeckParse(DeckBuckets::allocate($parse->lines, $this->choices));
    }

    public function render(): View
    {
        return view('livewire.storefront.deck-import');
    }
}
