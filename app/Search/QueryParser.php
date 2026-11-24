<?php

namespace App\Search;

use App\Enums\QueryKind;
use App\Models\CardSet;
use App\Models\Printing;
use Normalizer;

/**
 * Answers one question before any Scout call: is this a search, or a destination?
 *
 * The input is split into tokens on whitespace and dashes. A set code is the normalised join of one or more
 * WHOLE leading tokens (`op` + `05`, or `op05`), matched against the codes of sets a customer may see; a
 * joined token such as `op05119` is never split, so `sv1` and `sv10` cannot be confused. Bind it as scoped:
 * the code map is read once per request.
 */
final class QueryParser
{
    /**
     * Regions a card number may be prefixed with (`ROTA-EN062`); any other letters are not a region.
     */
    private const REGIONS = ['en', 'jp', 'ja', 'de', 'fr', 'it', 'sp', 'pt', 'kr', 'ko', 'ae', 'th', 'tc', 'sc'];

    private const MAX_SET_JUMPS = 2;

    /** The most whitespace/dash tokens a set code plus a card number can span. */
    private const MAX_TOKENS = 6;

    /**
     * Normalised set code => ids of the visible sets that carry it.
     *
     * @var ?array<string, list<int>>
     */
    private ?array $setIdsByCode = null;

    public function parse(string $raw): ParsedQuery
    {
        $text = trim($raw);
        $input = $this->fold($text);

        if (str_contains($input, '/')) {
            return $this->parseFraction($text, $input);
        }

        return $this->parseTokens($text, $this->tokens($input)) ?? new ParsedQuery(QueryKind::Text, $text);
    }

    /**
     * `SV3PT5 2/165` and `SV4a 190/190`: the part before the slash ends with the card number.
     */
    private function parseFraction(string $text, string $input): ParsedQuery
    {
        if (preg_match('~^(?<head>[^/]*?)(?<number>\d{1,4})\s*/\s*\d{1,4}$~', $input, $matches) !== 1) {
            return new ParsedQuery(QueryKind::Text, $text);
        }

        $tokens = $this->tokens($matches['head']);

        if ($tokens === []) {
            return new ParsedQuery(QueryKind::Text, $text, numberPlain: (int) $matches['number']);
        }

        return $this->parseTokens($text, [...$tokens, $matches['number']]) ?? new ParsedQuery(QueryKind::Text, $text);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function parseTokens(string $text, array $tokens): ?ParsedQuery
    {
        $setMatch = null;

        // A set code spans at most a few tokens and a card number one or two more, so a longer
        // input is plain text. The bound also keeps the loop below from growing with pasted text.
        if (count($tokens) > self::MAX_TOKENS) {
            return null;
        }

        // Longest code first: `bt-1-001` reads `bt1` before it could read `bt` + junk.
        for ($count = count($tokens); $count >= 1; $count--) {
            $setIds = $this->setIds()[$this->normalise(implode('', array_slice($tokens, 0, $count)))] ?? null;

            if ($setIds === null) {
                continue;
            }

            $rest = array_slice($tokens, $count);

            if ($rest === []) {
                $setMatch ??= $setIds;

                continue;
            }

            $number = $this->cardNumber($rest);

            if ($number === null) {
                continue;
            }

            $printingIds = $this->printingIds($setIds, $number);

            if ($printingIds !== []) {
                return new ParsedQuery(QueryKind::Printing, $text, $setIds, $printingIds);
            }

            // A real set with an unknown number is still worth a jump to the set.
            $setMatch ??= $setIds;
        }

        return $setMatch === null
            ? null
            : new ParsedQuery(QueryKind::Set, $text, array_slice($setMatch, 0, self::MAX_SET_JUMPS));
    }

    /**
     * The card number the remaining tokens name: `119`, `en062` or `en` + `062`.
     *
     * @param  list<string>  $rest
     */
    private function cardNumber(array $rest): ?int
    {
        if (count($rest) === 2 && in_array($rest[0], self::REGIONS, true)) {
            array_shift($rest);
        }

        if (count($rest) !== 1) {
            return null;
        }

        $pattern = '/^(?:'.implode('|', self::REGIONS).')?(?<number>\d{1,4})$/';

        return preg_match($pattern, $rest[0], $matches) === 1 ? (int) $matches['number'] : null;
    }

    /**
     * @param  list<int>  $setIds
     * @return list<int>
     */
    private function printingIds(array $setIds, int $numberPlain): array
    {
        return Printing::query()
            ->searchVisible()
            ->whereIn('set_id', $setIds)
            ->get(['id', 'number'])
            ->filter(fn (Printing $printing): bool => Printing::plainNumber($printing->number) === $numberPlain)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, list<int>>
     */
    private function setIds(): array
    {
        if ($this->setIdsByCode === null) {
            $map = [];

            foreach (CardSet::query()->searchVisible()->orderBy('id')->get(['id', 'code']) as $set) {
                $code = $this->normalise($set->code);

                if ($code !== '') {
                    $map[$code][] = $set->id;
                }
            }

            $this->setIdsByCode = $map;
        }

        return $this->setIdsByCode;
    }

    /**
     * NFKC folds full-width letters, digits and dashes (`ＯＰ０５－１１９`) to ASCII; then lower case.
     */
    private function fold(string $value): string
    {
        return mb_strtolower(Normalizer::normalize($value, Normalizer::NFKC) ?: $value);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        return preg_split('/[\s\-\x{2010}-\x{2015}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value)) ?? '';
    }
}
