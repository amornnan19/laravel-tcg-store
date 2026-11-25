<?php

namespace App\Actions;

use App\Enums\DeckLineKind;
use App\Enums\DeckMatchedBy;
use App\Enums\Language;
use App\Exceptions\FuzzySearchUnavailable;
use App\Models\Card;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use App\Search\FuzzyCardMatcher;
use App\Search\QueryParser;
use App\Support\Deck\DeckBuckets;
use App\Support\Deck\DeckLine;
use App\Support\Deck\DeckMatch;
use App\Support\Deck\DeckParse;
use App\Support\Deck\DeckVariant;
use App\Support\Deck\NameKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads a pasted deck list, line by line, and says what the shop can fill from it. It only reads: nothing is
 * reserved, saved or sent.
 *
 * A card line is matched in this order, first hit wins: a set code with a card number (`OP05-119`, `OBF 125`); a bare
 * card number under a `Set:` line; an exact card name in any of the three languages; a close name the search engine
 * ranks at or above `deck.fuzzy_min_score`. Every hit is limited to the chosen game's visible printings, so another
 * game's card, a trashed card or an inactive game never matches. Queries stay bounded by batching each step across all
 * lines, so a long list costs a handful of queries, not one per line.
 *
 * The caller checks the list's size (`deck.max_chars`, `deck.max_lines`) before calling.
 */
class ParseDeckList
{
    /** Section headers that deck builders export, with an optional `: 12` or `(12)` count. */
    private const HEADER = '/^(?:pok[eé]mon|trainers?|energy|total cards|leader|main deck|side deck|extra deck|don!*\s*deck)\s*(?:[:：]\s*[0-9]*|\(\s*[0-9]+\s*\))?$/iu';

    private const DIRECTIVE = '/^(?:set|เซ็ต|เซต)\s*[:：]\s*(?<code>.+)$/iu';

    /** A leading `4`, `4x`, `4 x` or `4 `; the `x` is only a multiplier when it touches the digits or has space after it. */
    private const LEADING_QTY = '/^(?<qty>[0-9]{1,9})(?:[x×]\s*|\s+[x×]\s+|\s+)(?<body>\S.*)$/iu';

    private const TRAILING_QTY = '/^(?<body>.+?)\s+[x×]\s*(?<qty>[0-9]{1,9})$/iu';

    public function __construct(private QueryParser $parser, private FuzzyCardMatcher $fuzzyMatcher) {}

    /**
     * Reads and prices a list in one go. A caller that prices the same list again and again (the importer page does,
     * on every request) keeps the `DeckMatch` and calls `price()` instead.
     */
    public function handle(Game $game, string $raw, ?Language $language = null): DeckParse
    {
        return $this->price($this->match($game, $raw), $language);
    }

    /**
     * The expensive half: which card each line names. Reads the catalogue and may ask the search engine, so run it
     * once per check and keep the result.
     */
    public function match(Game $game, string $raw): DeckMatch
    {
        /** @var array<int, array<string, mixed>> $records Finished lines by number: everything except card lines awaiting a match. */
        $records = [];
        $drafts = $this->read($raw, $records);

        // Visible printings of this game found so far, by id.
        $printings = new Collection;

        $this->matchByCode($game, $drafts, $printings);
        $this->matchByName($game, $drafts, $printings);
        $this->matchByFuzzyName($game, $drafts, $printings);

        foreach ($drafts as $number => $draft) {
            $first = $draft['by'] === null ? null : $printings[$draft['printingIds'][0]];

            $records[$number] = $this->record(
                $number,
                $draft['text'],
                $draft['by'] === null ? DeckLineKind::Unmatched : DeckLineKind::Matched,
                qty: $draft['qty'],
                capped: $draft['capped'],
                body: $draft['body'],
                by: $draft['by'],
                printingIds: $draft['printingIds'],
                ambiguous: $draft['ambiguous'],
                name: $first?->display_name,
                code: $first?->display_code,
            );
        }

        ksort($records);

        return new DeckMatch(array_values($records));
    }

    /**
     * The cheap half: stock and prices for the printings a match found, then the buckets. One query for the
     * printings and one for their variants; it never touches the search engine.
     */
    public function price(DeckMatch $match, ?Language $language = null): DeckParse
    {
        $ids = array_values(array_unique(array_merge([], ...array_column($match->lines, 'printingIds'))));

        $printings = new Collection;

        foreach (array_chunk($ids, 400) as $chunk) {
            Printing::query()->searchVisible()->whereKey($chunk)->with(['card', 'set'])->get()
                ->each(fn (Printing $printing) => $printings->put($printing->id, $printing));
        }

        $variants = $this->variantsFor($printings->keys()->all());

        return new DeckParse(array_map(fn (array $record): DeckLine => $this->line($record, $printings, $variants, $language), $match->lines));
    }

    /**
     * Splits the paste into lines. Lines that are not cards are finished here; card lines come back as drafts.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{number: int, text: string, qty: int, capped: bool, body: string, set: ?string, ambiguous: bool, by: ?DeckMatchedBy, printingIds: list<int>}>
     */
    private function read(string $raw, array &$lines): array
    {
        $drafts = [];
        $declaredSet = null;

        foreach (preg_split('/\R/u', $raw) ?: [] as $index => $original) {
            $number = $index + 1;
            // NBSP and the ideographic space are whitespace too, which `trim()` does not know.
            $text = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $original) ?? '';

            if ($text === '') {
                continue;
            }

            $folded = NameKey::fold($text);

            if (str_starts_with($folded, '//') || str_starts_with($folded, '#') || preg_match(self::HEADER, $folded) === 1) {
                $lines[$number] = $this->record($number, $text, DeckLineKind::Ignored);

                continue;
            }

            if (preg_match(self::DIRECTIVE, $folded, $directive) === 1) {
                $declaredSet = trim($directive['code']);
                $lines[$number] = $this->record($number, $text, DeckLineKind::Directive);

                continue;
            }

            [$qty, $body] = $this->quantity($folded);
            $max = (int) config('deck.max_qty');

            // `0x Luffy` is not a card line: say so instead of reading it as one copy.
            if ($qty === 0) {
                $lines[$number] = $this->record($number, $text, DeckLineKind::Unmatched, qty: 0, body: $body, zero: true);

                continue;
            }

            $drafts[$number] = [
                'number' => $number,
                'text' => $text,
                'qty' => min($qty, $max),
                'capped' => $qty > $max,
                'body' => $body,
                'set' => $declaredSet,
                'ambiguous' => false,
                'by' => null,
                'printingIds' => [],
            ];
        }

        return $drafts;
    }

    /**
     * @return array{int, string} The quantity (1 when the line has none) and what is left of the line.
     */
    private function quantity(string $text): array
    {
        if (preg_match(self::LEADING_QTY, $text, $matches) === 1) {
            return [(int) $matches['qty'], trim($matches['body'])];
        }

        if (preg_match(self::TRAILING_QTY, $text, $matches) === 1) {
            return [(int) $matches['qty'], trim($matches['body'])];
        }

        return [1, $text];
    }

    /**
     * Steps 1 and 2: a set code with a card number, then a bare number under a declared set. The sets the lines name
     * are read in one query for the whole list, not one per line.
     *
     * @param  array<int, array<string, mixed>>  $drafts
     * @param  Collection<int, Printing>  $printings
     */
    private function matchByCode(Game $game, array &$drafts, Collection $printings): void
    {
        $candidates = [];

        foreach ($drafts as $number => $draft) {
            $found = $this->codeCandidates($draft['body']);
            $by = DeckMatchedBy::Code;

            if ($found === [] && $draft['set'] !== null && preg_match('/^[0-9]{1,4}$/', $draft['body']) === 1) {
                $found = $this->parser->codeCandidates($draft['set'].' '.$draft['body']);
                $by = DeckMatchedBy::DeclaredSet;
            }

            if ($found !== []) {
                $candidates[$number] = [$found, $by];
            }
        }

        $numbersBySet = $this->numbersBySet($game, collect($candidates)->flatMap(fn (array $hit): array => collect($hit[0])->flatMap(fn (array $candidate): array => $candidate['setIds'])->all())->unique()->values()->all());

        $ids = [];

        foreach ($candidates as $number => [$found, $by]) {
            foreach ($found as $candidate) {
                $hits = [];

                foreach ($candidate['setIds'] as $setId) {
                    foreach ($numbersBySet[$setId] ?? [] as [$id, $plain]) {
                        if ($plain === $candidate['number']) {
                            $hits[] = $id;
                        }
                    }
                }

                // The first reading that names a visible printing of this game wins, like `QueryParser::parse()`.
                if ($hits !== []) {
                    $drafts[$number]['by'] = $by;
                    $drafts[$number]['printingIds'] = $hits;
                    $ids = [...$ids, ...$hits];

                    break;
                }
            }
        }

        $this->loadPrintings($game, 'id', $ids, $printings);
    }

    /**
     * The code readings of a line: its first one to three tokens, then its last two (`Charizard ex OBF 125`).
     *
     * @return list<array{setIds: list<int>, number: int}>
     */
    private function codeCandidates(string $body): array
    {
        $tokens = preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $attempts = [];

        for ($count = 1; $count <= min(3, count($tokens)); $count++) {
            $attempts[] = array_slice($tokens, 0, $count);
        }

        if (count($tokens) > 2) {
            $attempts[] = array_slice($tokens, -2);
        }

        $candidates = [];

        foreach ($attempts as $attempt) {
            $candidates = [...$candidates, ...$this->parser->codeCandidates(implode(' ', $attempt))];
        }

        return $candidates;
    }

    /**
     * The visible printings of this game in those sets, as `set id => [[printing id, plain number], ...]`.
     *
     * @param  list<int>  $setIds
     * @return array<int, list<array{int, ?int}>>
     */
    private function numbersBySet(Game $game, array $setIds): array
    {
        $map = [];

        foreach (array_chunk($setIds, 400) as $chunk) {
            Printing::query()
                ->searchVisible()
                ->whereHas('set', fn (Builder $set) => $set->where('game_id', $game->id))
                ->whereIn('set_id', $chunk)
                ->orderBy('id')
                ->get(['id', 'set_id', 'number'])
                ->each(function (Printing $printing) use (&$map): void {
                    $map[$printing->set_id][] = [$printing->id, Printing::plainNumber($printing->number)];
                });
        }

        return $map;
    }

    /**
     * Step 3: an exact card name of the game, in any of its three languages, ignoring case and runs of spaces.
     *
     * @param  array<int, array<string, mixed>>  $drafts
     * @param  Collection<int, Printing>  $printings
     */
    private function matchByName(Game $game, array &$drafts, Collection $printings): void
    {
        $keys = [];

        foreach ($drafts as $draft) {
            if ($draft['by'] === null) {
                $keys[NameKey::of($draft['body'])] = true;
            }
        }

        if ($keys === []) {
            return;
        }

        $cardIdsByKey = [];

        foreach (array_chunk(array_keys($keys), 200) as $chunk) {
            // `lower()` is ASCII-only on SQLite, so there a stored name with full-width Latin or a capital accented
            // letter misses this step (MySQL and Postgres fold them); the fuzzy step then catches what is left.
            $cards = Card::query()
                ->where('game_id', $game->id)
                ->where(function (Builder $builder) use ($chunk): void {
                    foreach (['name_en', 'name_ja', 'name_th'] as $column) {
                        $builder->orWhereIn(DB::raw("lower({$column})"), $chunk);
                    }
                })
                ->get(['id', 'name_en', 'name_ja', 'name_th']);

            foreach ($cards as $card) {
                foreach (['name_en', 'name_ja', 'name_th'] as $column) {
                    $key = NameKey::of((string) $card->{$column});

                    if (isset($keys[$key])) {
                        $cardIdsByKey[$key][$card->id] = $card->id;
                    }
                }
            }
        }

        $this->loadPrintings($game, 'card_id', collect($cardIdsByKey)->flatten()->unique()->values()->all(), $printings);

        foreach ($drafts as $number => $draft) {
            if ($draft['by'] !== null) {
                continue;
            }

            $this->claimCards($drafts[$number], array_values($cardIdsByKey[NameKey::of($draft['body'])] ?? []), DeckMatchedBy::Name, $printings);
        }
    }

    /**
     * Step 4: the engine's best guess for lines nothing else matched, at most `deck.fuzzy_max_lines` of them.
     *
     * @param  array<int, array<string, mixed>>  $drafts
     * @param  Collection<int, Printing>  $printings
     */
    private function matchByFuzzyName(Game $game, array &$drafts, Collection $printings): void
    {
        $guesses = [];

        // A long body is not a card name: skip it before it costs an engine search.
        $candidates = array_filter($drafts, fn (array $draft): bool => $draft['by'] === null && mb_strlen($draft['body']) <= (int) config('deck.fuzzy_max_chars'));

        foreach (array_slice(array_keys($candidates), 0, (int) config('deck.fuzzy_max_lines')) as $number) {
            try {
                $match = $this->fuzzyMatcher->match($game, $drafts[$number]['body']);
            } catch (FuzzySearchUnavailable) {
                // The engine is down: the rest of the list would only wait for the same timeout.
                break;
            }

            if ($match !== null && $match['score'] >= (float) config('deck.fuzzy_min_score')) {
                $guesses[$number] = $match['card_id'];
            }
        }

        $this->loadPrintings($game, 'card_id', array_values(array_unique($guesses)), $printings);

        foreach ($guesses as $number => $cardId) {
            $this->claimCards($drafts[$number], [$cardId], DeckMatchedBy::Fuzzy, $printings);
        }
    }

    /**
     * A card with no visible printing is no match: the line stays unmatched.
     *
     * @param  array<string, mixed>  $draft
     * @param  list<int>  $cardIds
     * @param  Collection<int, Printing>  $printings
     */
    private function claimCards(array &$draft, array $cardIds, DeckMatchedBy $by, Collection $printings): void
    {
        $ids = $printings->filter(fn (Printing $printing): bool => in_array($printing->card_id, $cardIds, true))->keys()->all();

        if ($ids !== []) {
            $draft['by'] = $by;
            $draft['printingIds'] = $ids;
            $draft['ambiguous'] = $by === DeckMatchedBy::Name
                && $printings->only($ids)->pluck('card_id')->unique()->count() > 1;
        }
    }

    /**
     * Loads this game's visible printings (with card and set) into `$printings`.
     *
     * @param  'id'|'card_id'  $column
     * @param  list<int>  $ids
     * @param  Collection<int, Printing>  $printings
     */
    private function loadPrintings(Game $game, string $column, array $ids, Collection $printings): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 400) as $chunk) {
            Printing::query()
                ->searchVisible()
                ->whereHas('set', fn (Builder $set) => $set->where('game_id', $game->id))
                ->whereIn($column, $chunk)
                ->with(['card', 'set'])
                ->orderBy('id')
                ->get()
                ->each(fn (Printing $printing) => $printings->put($printing->id, $printing));
        }
    }

    /**
     * Purchasable variants of those printings, with live reservations loaded, grouped by printing.
     *
     * @param  list<int>  $printingIds
     * @return Collection<int, Collection<int, Variant>>
     */
    private function variantsFor(array $printingIds): Collection
    {
        $variants = new Collection;

        foreach (array_chunk($printingIds, 400) as $chunk) {
            $variants = $variants->concat(
                Variant::query()->purchasable()->withReservedQty()->whereIn('printing_id', $chunk)->get(),
            );
        }

        return $variants->groupBy('printing_id');
    }

    /**
     * One line of a match, as plain data.
     *
     * @param  list<int>  $printingIds
     * @return array<string, mixed>
     */
    private function record(int $number, string $text, DeckLineKind $kind, int $qty = 0, bool $capped = false, string $body = '', bool $zero = false, ?DeckMatchedBy $by = null, array $printingIds = [], bool $ambiguous = false, ?string $name = null, ?string $code = null): array
    {
        return [
            'number' => $number,
            'text' => $text,
            'body' => $body,
            'kind' => $kind->value,
            'qty' => $qty,
            'qtyCapped' => $capped,
            'qtyZero' => $zero,
            'matchedBy' => $by?->value,
            'printingIds' => $printingIds,
            'ambiguous' => $ambiguous,
            'matchedName' => $name,
            'matchedCode' => $code,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  Collection<int, Printing>  $printings
     * @param  Collection<int, Collection<int, Variant>>  $variants
     */
    private function line(array $record, Collection $printings, Collection $variants, ?Language $language): DeckLine
    {
        $kind = DeckLineKind::from($record['kind']);

        if ($kind !== DeckLineKind::Matched) {
            return DeckBuckets::assign(new DeckLine($record['number'], $record['text'], $kind, $record['qty'], $record['qtyCapped'], body: $record['body'], qtyZero: $record['qtyZero']), null);
        }

        $options = [];
        $otherLanguages = 0;

        // A printing that is no longer visible (trashed since the match) simply has no options any more.
        foreach (array_filter(array_map(fn (int $id): ?Printing => $printings->get($id), $record['printingIds'])) as $printing) {
            $excluded = $language !== null && $printing->language !== $language;

            foreach ($variants->get($printing->id, []) as $variant) {
                if ($excluded) {
                    $otherLanguages += $variant->availableQty() > 0 ? 1 : 0;

                    continue;
                }

                $options[$variant->id] = new DeckVariant(
                    id: $variant->id,
                    printingId: $printing->id,
                    availableQty: $variant->availableQty(),
                    priceSatang: (int) $variant->price_satang,
                    condition: $variant->condition,
                    finish: $variant->finish,
                    language: $printing->language,
                    name: $printing->display_name,
                    code: $printing->display_code,
                );
            }
        }

        uasort($options, fn (DeckVariant $a, DeckVariant $b): int => [$a->priceSatang, $a->id] <=> [$b->priceSatang, $b->id]);

        return DeckBuckets::assign(new DeckLine(
            number: $record['number'],
            text: $record['text'],
            kind: $kind,
            qty: $record['qty'],
            qtyCapped: $record['qtyCapped'],
            matchedBy: DeckMatchedBy::from($record['matchedBy']),
            printingIds: $record['printingIds'],
            matchedName: $record['matchedName'],
            matchedCode: $record['matchedCode'],
            variants: $options,
            ambiguous: $record['ambiguous'],
            otherLanguagesAvailable: $otherLanguages,
            body: $record['body'],
        ), null);
    }
}
