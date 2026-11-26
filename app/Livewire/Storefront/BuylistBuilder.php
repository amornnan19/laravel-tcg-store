<?php

namespace App\Livewire\Storefront;

use App\Actions\ParseDeckList;
use App\Actions\SubmitBuylist;
use App\Enums\BuylistPayout;
use App\Enums\DeckLineKind;
use App\Enums\DeckMatchedBy;
use App\Enums\Finish;
use App\Exceptions\BuylistQuotaFull;
use App\Exceptions\BuylistQuoteChanged;
use App\Models\BuylistPrice;
use App\Models\Game;
use App\Models\Printing;
use App\Support\BuylistQuote;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The `/buylist` page: the customer finds cards the shop buys (one at a time, or a pasted list), picks a condition and a
 * quantity per line and sees what they would be paid in cash and in store credit before anything is sent.
 *
 * The list is `lines`, `{buylist_price_id, qty}`, kept in locked state and mirrored to the session on every change, so a
 * guest who logs in comes back to the same list. Totals are never stored: every render reads the current price rows.
 * Paste matching is the expensive half (set codes, names, the search engine), so it runs only in `check()`, which is
 * rate limited, and its result is kept in locked state. Submitting sends the lines and a signature of what was shown to
 * `SubmitBuylist`, which refuses when anything under that signature has moved.
 */
class BuylistBuilder extends Component
{
    private const CHECKS_PER_MINUTE = 20;

    private const UNBOUGHT_SHOWN = 20;

    /**
     * The lines as priced for this request only: private, so it is never sent to the browser or kept.
     *
     * @var ?array<int, array<string, mixed>>
     */
    private ?array $resolvedLines = null;

    /**
     * `search` or `paste`; changed only by `setMode()`.
     */
    #[Locked]
    public string $mode = 'search';

    /**
     * @var list<array{buylist_price_id: int, qty: int}>
     */
    #[Locked]
    public array $lines = [];

    public string $query = '';

    public string $raw = '';

    public ?int $gameId = null;

    public string $payout = 'credit';

    /**
     * The text the shown paste result was matched from.
     */
    #[Locked]
    public string $checkedRaw = '';

    #[Locked]
    public bool $checked = false;

    /**
     * Lines of the last paste the shop does not buy, as pasted: the first `UNBOUGHT_SHOWN`, then a count of the rest.
     *
     * @var list<string>
     */
    #[Locked]
    public array $unbought = [];

    #[Locked]
    public int $unboughtMore = 0;

    #[Locked]
    public int $pastedAdded = 0;

    /**
     * Pasted lines whose name fits several printings, so the shop will not guess: as pasted, with the name to search
     * for. The first `UNBOUGHT_SHOWN`, then a count of the rest.
     *
     * @var list<array{text: string, name: string}>
     */
    #[Locked]
    public array $ambiguous = [];

    #[Locked]
    public int $ambiguousMore = 0;

    /**
     * Pasted lines the shop buys but whose weekly quota is used up, as pasted.
     *
     * @var list<string>
     */
    #[Locked]
    public array $quotaFullLines = [];

    #[Locked]
    public int $quotaFullMore = 0;

    /**
     * What the last add or paste refused or explained, shown next to the list, or next to the search row it is about
     * when `noticeFor` names one.
     */
    #[Locked]
    public ?string $notice = null;

    /**
     * The search row (`printing id-finish`) the notice belongs to, or null for the page.
     */
    #[Locked]
    public ?string $noticeFor = null;

    /**
     * What the last submit refused, shown inside the submit bar next to the button.
     */
    #[Locked]
    public ?string $submitNotice = null;

    public function mount(): void
    {
        $this->gameId = $this->games->first()?->id;

        // The builder's own state, not an after-login one-shot value: it stays until the list is submitted.
        $stored = session()->get('buylist.lines', []);
        $lines = [];

        foreach (is_array($stored) ? $stored : [] as $line) {
            $id = (int) ($line['buylist_price_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);

            if ($id > 0 && $qty > 0 && ! isset($lines[$id])) {
                $lines[$id] = ['buylist_price_id' => $id, 'qty' => min($qty, (int) config('buylist.max_qty_per_line'))];
            }
        }

        $this->lines = array_slice(array_values($lines), 0, (int) config('buylist.max_lines'));
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['search', 'paste'], true)) {
            $this->mode = $mode;
        }
    }

    /**
     * Puts a printing on the list, at its best open condition, or raises its line by one. The row is found again here:
     * the browser only names a printing and a finish. A printing whose every condition has used up its quota says so.
     */
    public function add(int $printingId, string $finish): void
    {
        $this->clearNotices();
        $finishChoice = Finish::tryFrom($finish);
        $key = $printingId.'-'.$finish;

        $row = $finishChoice === null ? null : $this->bestRows([$printingId])->first(fn (BuylistPrice $price): bool => $price->finish === $finishChoice);

        // Not row-scoped: a row the shop stopped buying leaves the results, and its notice must not go with it.
        if ($row === null) {
            $this->notice = __('storefront.buylist.no_longer');

            return;
        }

        [$known, $live] = $this->lineState();

        $status = $this->putOnList($row, 1, $known, $live);

        if ($status === 'full') {
            $this->notice = __('storefront.buylist.quota_full');
            $this->noticeFor = $key;
        } elseif ($status === 'too_many') {
            $this->notice = __('storefront.buylist.too_many_lines', ['max' => config('buylist.max_lines')]);
            $this->noticeFor = $key;
        }

        $this->persist();
    }

    public function increment(int $priceId): void
    {
        $this->changeQty($priceId, 1);
    }

    public function decrement(int $priceId): void
    {
        $this->changeQty($priceId, -1);
    }

    /**
     * Another condition for a line, by the price row of that condition. Only a buyable row of the same printing and
     * finish is taken; any other id is the client's claim and is ignored.
     */
    public function setCondition(int $fromPriceId, int $toPriceId): void
    {
        $this->clearNotices();
        $line = $this->resolve()[$fromPriceId] ?? null;
        $target = $line === null ? null : $line['options']->firstWhere('id', $toPriceId);

        // A condition whose quota is used up cannot be picked, from a line that is full as from any other.
        if ($line === null || ! $line['buyable'] || $target === null || $target->remainingQuota() === 0 || isset($this->resolve()[$toPriceId])) {
            return;
        }

        $this->lines = array_map(
            fn (array $stored): array => $stored['buylist_price_id'] === $fromPriceId ? ['buylist_price_id' => $toPriceId, 'qty' => $stored['qty']] : $stored,
            $this->lines,
        );

        $this->persist();
    }

    public function remove(int $priceId): void
    {
        $this->clearNotices();
        $this->lines = array_values(array_filter($this->lines, fn (array $line): bool => $line['buylist_price_id'] !== $priceId));
        $this->persist();
    }

    /**
     * Reads the pasted list against the catalogue and puts every line the shop buys on the list. A line it does not buy,
     * one whose quota is used up, and one whose name fits several printings (it will not guess which) are each named in
     * their own bucket, never dropped silently. Rate limited like the deck importer's check, on its own key.
     */
    public function check(): void
    {
        $this->clearNotices();

        if ($this->throttled()) {
            return;
        }

        $this->validate();

        $game = $this->games->firstWhere('id', $this->gameId);
        $match = app(ParseDeckList::class)->match($game, $this->raw)->toArray();

        $ids = collect($match)
            ->where('kind', DeckLineKind::Matched->value)
            ->flatMap(fn (array $record): array => $record['printingIds'])
            ->unique()
            ->values()
            ->all();

        $best = $this->bestRows($ids)->groupBy('printing_id');
        [$known, $live] = $this->lineState();
        $unbought = [];
        $ambiguous = [];
        $full = [];
        $added = 0;

        foreach ($match as $record) {
            $kind = DeckLineKind::from($record['kind']);

            if ($kind === DeckLineKind::Ignored || $kind === DeckLineKind::Directive) {
                continue;
            }

            $bought = $kind === DeckLineKind::Matched && ! $record['qtyZero']
                ? collect($record['printingIds'])->filter(fn (int $id): bool => $best->has($id))->values()
                : collect();

            if ($bought->isEmpty()) {
                $unbought[] = $record['text'];

                continue;
            }

            // A name can fit a common and a rare printing, or two cards: the customer says which, the shop does not guess.
            $byName = in_array($record['matchedBy'], [DeckMatchedBy::Name->value, DeckMatchedBy::Fuzzy->value], true);

            if ($record['ambiguous'] || ($byName && $bought->count() > 1)) {
                $ambiguous[] = ['text' => $record['text'], 'name' => (string) ($record['matchedName'] ?? $record['body'])];

                continue;
            }

            $status = $this->putOnList($best->get($bought->first())->first(), $record['qty'], $known, $live);

            match ($status) {
                'added' => $added++,
                'full' => $full[] = $record['text'],
                default => $unbought[] = $record['text'],
            };
        }

        $this->checkedRaw = $this->raw;
        $this->checked = true;
        $this->pastedAdded = $added;
        $this->unbought = array_slice($unbought, 0, self::UNBOUGHT_SHOWN);
        $this->unboughtMore = max(0, count($unbought) - self::UNBOUGHT_SHOWN);
        $this->ambiguous = array_slice($ambiguous, 0, self::UNBOUGHT_SHOWN);
        $this->ambiguousMore = max(0, count($ambiguous) - self::UNBOUGHT_SHOWN);
        $this->quotaFullLines = array_slice($full, 0, self::UNBOUGHT_SHOWN);
        $this->quotaFullMore = max(0, count($full) - self::UNBOUGHT_SHOWN);

        $this->persist();
        $this->dispatch('buylist-checked');
    }

    /**
     * Switches to search with the name of an ambiguous pasted line filled in. The name comes from the shown result, not
     * from the browser.
     */
    public function searchFor(int $index): void
    {
        $name = $this->ambiguous[$index]['name'] ?? null;

        if ($name === null) {
            return;
        }

        $this->clearNotices();
        $this->mode = 'search';
        $this->query = $name;
    }

    /**
     * Sends the list. A guest is sent to log in and comes back to the same list. Everything sent is recomputed here from
     * the current rows; the browser only says which signature it saw.
     */
    public function submit(string $shownSignature, string $shownPrices, SubmitBuylist $submitBuylist): void
    {
        $this->clearNotices();

        if (auth()->guest()) {
            session()->put('url.intended', route('buylist'));
            $this->redirectRoute('login');

            return;
        }

        if (auth()->user()->is_staff) {
            $this->submitNotice = __('storefront.buylist.staff');

            return;
        }

        $submittable = array_values(array_filter($this->resolve(), fn (array $line): bool => $line['submittable']));

        if ($submittable === []) {
            $this->submitNotice = __('storefront.buylist.nothing_to_submit');

            return;
        }

        try {
            $submission = $submitBuylist->handle(
                auth()->user(),
                $this->payoutChoice(),
                array_map(fn (array $line): array => ['buylist_price_id' => $line['row']->getKey(), 'qty' => $line['qty']], $submittable),
                $shownSignature,
            );
        } catch (BuylistQuoteChanged) {
            // Read again, and say what moved: a quantity the quota cut is not a price change.
            $this->resolvedLines = null;
            $now = array_filter($this->resolve(), fn (array $line): bool => $line['submittable']);
            $samePrices = hash_equals(BuylistQuote::pricesSignature(array_map(fn (array $line): array => ['price' => $line['row'], 'qty' => $line['qty']], $now)), $shownPrices);
            $this->submitNotice = __($samePrices ? 'storefront.buylist.quantity_changed' : 'storefront.buylist.quote_changed');

            return;
        } catch (BuylistQuotaFull $full) {
            $this->submitNotice = __('storefront.buylist.quota_full_submit', ['card' => $full->cardName, 'n' => $full->remaining]);
            $this->resolvedLines = null;

            return;
        } catch (InvalidArgumentException) {
            $this->submitNotice = __('storefront.buylist.refused');

            return;
        }

        $this->lines = [];
        $this->redirectRoute('buylist.show', ['submission' => $submission->number]);
    }

    /**
     * Whether the paste rate limit is used up; counts this attempt when it is not. Says how long to wait when it is.
     */
    private function throttled(): bool
    {
        $key = self::throttleKey((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::CHECKS_PER_MINUTE)) {
            $this->addError('raw', __('storefront.buylist.errors.throttled', ['seconds' => RateLimiter::availableIn($key)]));

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    public static function throttleKey(string $ip): string
    {
        return DeckImport::throttleKey($ip, 'buylist-check');
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

                    if (count(array_filter($lines, fn (string $line): bool => preg_match('/^[\s\p{Z}]*$/u', $line) !== 1)) > (int) config('deck.max_lines')) {
                        $fail(__('storefront.buylist.errors.too_long', ['lines' => config('deck.max_lines')]));
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
            'raw.required' => __('storefront.buylist.errors.empty'),
            'raw.max' => __('storefront.buylist.errors.too_long', ['lines' => config('deck.max_lines')]),
            'gameId.required' => __('storefront.buylist.errors.game'),
            'gameId.exists' => __('storefront.buylist.errors.game'),
        ];
    }

    /**
     * @return Collection<int, Game>
     */
    #[Computed]
    public function games(): Collection
    {
        return Game::query()->where('is_active', true)->orderBy('sort')->get();
    }

    public function payoutChoice(): BuylistPayout
    {
        return BuylistPayout::tryFrom($this->payout) ?? BuylistPayout::Credit;
    }

    /**
     * Whether the pasted text has changed since the shown paste result was matched.
     */
    public function isPasteStale(): bool
    {
        return $this->checked && $this->raw !== $this->checkedRaw;
    }

    private function persist(): void
    {
        $this->resolvedLines = null;
        session()->put('buylist.lines', $this->lines);
    }

    private function clearNotices(): void
    {
        $this->notice = null;
        $this->noticeFor = null;
        $this->submitNotice = null;
    }

    private function changeQty(int $priceId, int $by): void
    {
        $this->clearNotices();
        $line = $this->resolve()[$priceId] ?? null;

        if ($line === null || ! $line['buyable'] || $line['quotaFull']) {
            return;
        }

        $qty = $line['qty'] + $by;

        if ($qty < 1 || $qty > $line['allowed']) {
            return;
        }

        $this->lines = array_map(
            fn (array $stored): array => $stored['buylist_price_id'] === $priceId ? ['buylist_price_id' => $priceId, 'qty' => $qty] : $stored,
            $this->lines,
        );

        $this->persist();
    }

    /**
     * Puts `$qty` of a price row on the list: raises the line of the same printing and finish when that line is still
     * bought and has quota left, else gives the line the row (a dead line is replaced, never raised unseen), else adds a
     * line. Returns `added`, `full` (the row has no quota left, so nothing changed) or `too_many` (the list is full).
     *
     * @param  SupportCollection<int, BuylistPrice>  $known  The rows of the lines already on the list, by id; kept up to date.
     * @param  SupportCollection<int, bool>  $live  The ids of those lines that are still bought and have quota left.
     */
    private function putOnList(BuylistPrice $row, int $qty, SupportCollection $known, SupportCollection $live): string
    {
        if ($row->remainingQuota() === 0) {
            return 'full';
        }

        $max = (int) config('buylist.max_qty_per_line');

        foreach ($this->lines as $index => $line) {
            $existing = $known->get($line['buylist_price_id']);

            if ($existing === null || $existing->printing_id !== $row->printing_id || $existing->finish !== $row->finish) {
                continue;
            }

            if ($live->has($existing->getKey())) {
                $this->lines[$index]['qty'] = min($line['qty'] + $qty, $max);

                return 'added';
            }

            $this->lines[$index] = ['buylist_price_id' => $row->getKey(), 'qty' => min($qty, $max)];
            $known->put($row->getKey(), $row);
            $live->put($row->getKey(), true);

            return 'added';
        }

        if (count($this->lines) >= (int) config('buylist.max_lines')) {
            return 'too_many';
        }

        $this->lines[] = ['buylist_price_id' => $row->getKey(), 'qty' => min($qty, $max)];
        $known->put($row->getKey(), $row);
        $live->put($row->getKey(), true);

        return 'added';
    }

    /**
     * The price rows of the lines on the list by id, whether or not they are still bought, and which of them are live
     * (still bought, quota left).
     *
     * @return array{0: SupportCollection<int, BuylistPrice>, 1: SupportCollection<int, bool>}
     */
    private function lineState(): array
    {
        $ids = array_column($this->lines, 'buylist_price_id');
        $known = BuylistPrice::query()->whereKey($ids)->get()->keyBy('id');
        $live = BuylistPrice::query()->buyable()->whereKey($ids)->get()
            ->filter(fn (BuylistPrice $price): bool => $price->remainingQuota() !== 0)
            ->mapWithKeys(fn (BuylistPrice $price): array => [$price->getKey() => true]);

        return [$known, $live];
    }

    /**
     * For each printing and finish the shop buys, its best-condition row with quota left (a full one only when every
     * condition is full), normal finish before foil.
     *
     * @param  list<int>  $printingIds
     * @return SupportCollection<int, BuylistPrice>
     */
    private function bestRows(array $printingIds): SupportCollection
    {
        if ($printingIds === []) {
            return collect();
        }

        return BuylistPrice::query()
            ->buyable()
            ->whereIn('printing_id', $printingIds)
            ->with(['printing.card', 'printing.set'])
            ->get()
            // Normal before foil, and within a finish a condition with quota left before one without, so an add or a
            // paste lands on a row that can be sold.
            ->sortBy(fn (BuylistPrice $price): array => [$price->finish === Finish::Normal ? 0 : 1, $price->remainingQuota() === 0 ? 1 : 0, $price->condition->sort()])
            ->groupBy(fn (BuylistPrice $price): string => $price->printing_id.'-'.$price->finish->value)
            ->map(fn (SupportCollection $group): BuylistPrice => $group->first())
            ->values();
    }

    /**
     * Every line as it prices now, by price id: the row, the conditions it can be switched to, the quantity the shop
     * would take this week and what it comes to. A line whose row is no longer bought is `buyable = false` and counts
     * for nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolve(): array
    {
        if ($this->resolvedLines !== null) {
            return $this->resolvedLines;
        }

        $ids = array_column($this->lines, 'buylist_price_id');
        $rows = BuylistPrice::query()->whereKey($ids)->with(['printing.card', 'printing.set'])->get()->keyBy('id');
        $buyableIds = BuylistPrice::query()->buyable()->whereKey($ids)->pluck('id')->flip();
        $siblings = BuylistPrice::query()
            ->buyable()
            ->whereIn('printing_id', $rows->pluck('printing_id')->unique()->all())
            ->get()
            ->sortBy(fn (BuylistPrice $price): int => $price->condition->sort());

        $resolved = [];

        foreach ($this->lines as $line) {
            $row = $rows->get($line['buylist_price_id']);

            if ($row === null) {
                continue;
            }

            $buyable = $buyableIds->has($row->getKey());
            $remaining = $row->remainingQuota();
            $maxPerLine = (int) config('buylist.max_qty_per_line');
            $allowed = $remaining === null ? $maxPerLine : min($maxPerLine, $remaining);
            $quotaFull = $buyable && $remaining === 0;
            $qty = $buyable && ! $quotaFull ? max(1, min($line['qty'], $allowed)) : 0;

            $resolved[$row->getKey()] = [
                'row' => $row,
                'printing' => $row->printing,
                'options' => $siblings->filter(fn (BuylistPrice $price): bool => $price->printing_id === $row->printing_id && $price->finish === $row->finish)->values(),
                'buyable' => $buyable,
                'quotaFull' => $quotaFull,
                'remaining' => $remaining,
                'allowed' => $allowed,
                'storedQty' => $line['qty'],
                'qty' => $qty,
                'quotaCapped' => $buyable && ! $quotaFull && $remaining !== null && $line['qty'] >= $remaining && $qty === $remaining,
                'atMaxPerLine' => $buyable && $qty === $maxPerLine,
                'submittable' => $buyable && ! $quotaFull && $qty > 0,
                'cash' => $row->cash_satang * $qty,
                'credit' => $row->credit_satang * $qty,
            ];
        }

        return $this->resolvedLines = $resolved;
    }

    /**
     * Printings matching the search text that the shop buys, at most ten, each with the rows it buys. Cheap indexed
     * queries, so it runs in the render path with no rate limit.
     *
     * @return SupportCollection<int, array{printing: Printing, finish: Finish, best: BuylistPrice}>
     */
    private function searchResults(): SupportCollection
    {
        $term = mb_substr(trim($this->query), 0, 100);

        if ($term === '') {
            return collect();
        }

        $printingIds = BuylistPrice::query()
            ->buyable()
            ->whereHas('printing', fn (Builder $printing) => $printing->matchingText($term))
            ->select('printing_id')
            ->distinct()
            ->orderBy('printing_id')
            ->limit(10)
            ->pluck('printing_id')
            ->all();

        return $this->bestRows($printingIds)->map(fn (BuylistPrice $price): array => [
            'printing' => $price->printing,
            'finish' => $price->finish,
            'best' => $price,
        ])->sortBy(fn (array $result): array => [$result['printing']->id, $result['finish'] === Finish::Normal ? 0 : 1])->values();
    }

    public function render(): View
    {
        $rows = $this->resolve();
        $submittable = array_filter($rows, fn (array $line): bool => $line['submittable']);
        $cash = array_sum(array_column($submittable, 'cash'));
        $credit = array_sum(array_column($submittable, 'credit'));
        $payout = $this->payoutChoice();

        return view('livewire.storefront.buylist-builder', [
            'rows' => $rows,
            'results' => $this->mode === 'search' ? $this->searchResults() : collect(),
            'cashTotal' => $cash,
            'creditTotal' => $credit,
            'payoutTotal' => $payout === BuylistPayout::Credit ? $credit : $cash,
            'signature' => BuylistQuote::signature(array_map(fn (array $line): array => ['price' => $line['row'], 'qty' => $line['qty']], $submittable)),
            'pricesSignature' => BuylistQuote::pricesSignature(array_map(fn (array $line): array => ['price' => $line['row'], 'qty' => $line['qty']], $submittable)),
            'submittableCount' => count($submittable),
            'cardCount' => array_sum(array_column($submittable, 'qty')),
        ]);
    }
}
