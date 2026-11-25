<?php

namespace App\Support\Deck;

use Normalizer;

/**
 * How card names and deck lines are folded before they are compared.
 *
 * NFC, never NFKC: NFKC decomposes the Thai sara am (U+0E33) into nikhahit + sara aa, so a Thai name would no longer
 * equal the stored one. Only full-width ASCII (letters, digits, punctuation, the ideographic space) is folded.
 */
final class NameKey
{
    /**
     * The text as written, in one alphabet: NFC and full-width ASCII folded.
     */
    public static function fold(string $text): string
    {
        return mb_convert_kana(Normalizer::normalize($text, Normalizer::FORM_C) ?: $text, 'as');
    }

    /**
     * A card name as compared: folded, lower case, runs of whitespace collapsed.
     */
    public static function of(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\p{Z}]+/u', ' ', self::fold($name)) ?? ''));
    }

    /**
     * Whether the text has at least one letter or digit, so a search for it is a search at all.
     */
    public static function hasWordCharacters(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    /**
     * 1 for equal keys down to 0, from the Levenshtein distance over code points (not bytes, so Thai and Japanese
     * names are measured in characters). A `$floor` lets a hopeless pair return 0 without the O(n*m) work.
     */
    public static function similarity(string $a, string $b, float $floor = 0.0): float
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $longest = max(count($a), count($b));

        // The distance is at least the difference in length, so a pair that far apart cannot reach the floor.
        if ($longest === 0 || min(count($a), count($b)) / $longest < $floor) {
            return 0.0;
        }

        $previous = range(0, count($b));

        foreach ($a as $i => $left) {
            $current = [$i + 1];

            foreach ($b as $j => $right) {
                $current[$j + 1] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($left === $right ? 0 : 1));
            }

            $previous = $current;
        }

        return 1 - $previous[count($b)] / $longest;
    }
}
