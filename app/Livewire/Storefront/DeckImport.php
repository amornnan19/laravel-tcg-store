<?php

namespace App\Livewire\Storefront;

use App\Actions\ParseDeckList;
use App\Enums\Language;
use App\Models\Game;
use App\Support\Deck\DeckBuckets;
use App\Support\Deck\DeckLine;
use App\Support\Deck\DeckMatch;
use App\Support\Deck\DeckParse;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The deck-list importer page: the customer picks a game, pastes a list and sees what the shop can fill, in three
 * buckets. Nothing is saved or reserved here; every parse reads the catalogue as it is now.
 *
 * Matching is the expensive half of parsing (set codes, names, up to 30 engine searches). It runs once per check, or
 * once when the game changes, and only those actions (`check()`, `selectGame()`) are rate limited. Its outcome is kept
 * in locked state (`matched`); every later request only prices it again from the catalogue (`parse`) and lays the
 * customer's picks over that (`result`). The game and language change only through actions, never by a client write.
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

    public function mount(): void
    {
        $this->gameId = $this->games->first()?->id;
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
     */
    public static function throttleKey(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? (string) inet_pton($ip) : '';

        // An IPv4 client seen on a dual-stack socket arrives as ::ffff:a.b.c.d; its /64 would be all zeros and shared by every
        // IPv4 visitor, so it is keyed by the IPv4 address it carries.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return 'deck-check:'.inet_ntop(substr($packed, 12));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ip = bin2hex(substr((string) inet_pton($ip), 0, 8));
        }

        return 'deck-check:'.$ip;
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

        if ($this->isStale() || $found === null || ($found->variants[$variantId]->availableQty ?? 0) < 1) {
            return;
        }

        $this->choices[$line]['variant'] = $variantId;

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

        unset($this->result);
    }

    public function clear(): void
    {
        $this->pricedByLanguage = [];

        $this->reset('raw', 'checkedRaw', 'matched', 'checked', 'checkFailed', 'choices');
        $this->resetErrorBag();

        unset($this->parse, $this->result);
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

        return new DeckParse(array_map(function (DeckLine $line): DeckLine {
            $choice = $this->choices[$line->number] ?? null;

            if ($choice === null) {
                return $line;
            }

            return DeckBuckets::assign($line, $choice['variant'] ?? null)->withTakePartial($choice['partial'] ?? true);
        }, $parse->lines));
    }

    public function render(): View
    {
        return view('livewire.storefront.deck-import');
    }
}
