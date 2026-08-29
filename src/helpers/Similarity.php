<?php

namespace justinholtweb\friend\helpers;

use craft\helpers\StringHelper;

/**
 * The scoring primitives.
 *
 * Deliberately pure and static: no Craft services, no database, no settings. Everything here can
 * be reasoned about — and tested — with two strings and an expected number, which is the only way
 * a fuzzy matcher stays trustworthy as it grows.
 */
abstract class Similarity
{
    /**
     * Words carried by so many URLs that their presence says nothing about which page was meant.
     *
     * Kept short on purpose. An over-eager stop list throws away the signal in short slugs —
     * `/about-us` is two tokens, and dropping both leaves nothing to match on.
     */
    public const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'in', 'is', 'it',
        'its', 'of', 'on', 'or', 'that', 'the', 'to', 'was', 'were', 'will', 'with',
    ];

    /**
     * `similar_text()` is O(n³) in the worst case, so nothing longer than this is compared
     * character by character. Slugs and titles are far shorter; the cap exists for the pathological
     * URL somebody's scanner sends.
     */
    private const MAX_COMPARE_LENGTH = 255;

    /**
     * Break a slug, path or title into comparable tokens.
     *
     * Splits on anything that is not a letter or a digit, and also between a lowercase letter and
     * an uppercase one so `ourNewOffice` tokenises like `our-new-office`. Transliterates first, so
     * `café` and `cafe` are the same word — which matters because that substitution is exactly
     * what happens between a printed URL and a typed one.
     *
     * @param string[] $extraStopWords
     * @return string[] lowercase, de-duplicated, in order of first appearance
     */
    public static function tokenize(string $text, int $minLength = 2, array $extraStopWords = []): array
    {
        $text = StringHelper::toAscii($text);
        $text = (string)preg_replace('~(?<=[a-z0-9])(?=[A-Z])~', ' ', $text);
        $text = strtolower($text);

        $parts = preg_split('~[^a-z0-9]+~', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopWords = array_flip(array_merge(
            self::STOP_WORDS,
            array_map('strtolower', array_filter($extraStopWords, 'is_string'))
        ));

        $tokens = [];

        foreach ($parts as $part) {
            // Digits survive the length floor. A bare number in a slug is never noise — it is a
            // year, a version, a part number, an ordinal — and it is the whole difference between
            // `/guide-part-1` and `/guide-part-2`. Dropping it makes those two URLs identical to
            // the matcher, which is exactly the redirect nobody wants.
            $isNumber = ctype_digit($part);

            if (!$isNumber && mb_strlen($part) < max(1, $minLength)) {
                continue;
            }

            if (!$isNumber && isset($stopWords[$part])) {
                continue;
            }

            $tokens[$isNumber ? $part : self::stem($part)] = true;
        }

        // `array_keys()` on a set built with numeric-string keys hands back **ints**, because PHP
        // silently casts `$a['1']` to `$a[1]`. That quietly breaks the `string[]` this promises —
        // `in_array('1', $tokens, true)` fails, and so does every strict comparison downstream.
        return array_map('strval', array_keys($tokens));
    }

    /**
     * The lightest stemming that pays for itself: a trailing plural `s`.
     *
     * Anything more aggressive starts merging words that are genuinely different — and a wrong
     * merge here is a visitor sent to the wrong page, which is worse than a missed match.
     */
    public static function stem(string $token): string
    {
        if (mb_strlen($token) > 3 && str_ends_with($token, 's') && !str_ends_with($token, 'ss')) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * Sørensen–Dice coefficient over two token sets, 0–1.
     *
     * Chosen over Jaccard because it weights the overlap more generously, which suits short sets:
     * two tokens in common out of three and four is a strong signal for a URL slug and a weak one
     * by Jaccard's arithmetic.
     *
     * @param string[] $a
     * @param string[] $b
     */
    public static function dice(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }

        $shared = count(array_intersect($a, $b));

        return (2 * $shared) / (count($a) + count($b));
    }

    /**
     * Character-level similarity of two strings, 0–1.
     *
     * Catches what token overlap cannot: a typo, a transposition, a missing hyphen — the reasons a
     * URL was mistyped rather than moved.
     */
    public static function textRatio(string $a, string $b): float
    {
        $a = self::comparable($a);
        $b = self::comparable($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return max(0.0, min(1.0, $percent / 100));
    }

    /**
     * Levenshtein distance as a 0–1 similarity.
     *
     * PHP's `levenshtein()` refuses strings longer than 255 bytes outright, so the shared cap is
     * not an optimisation here — it is the difference between a number and a warning.
     */
    public static function levenshteinRatio(string $a, string $b): float
    {
        $a = self::comparable($a);
        $b = self::comparable($b);

        $longest = max(strlen($a), strlen($b));

        if ($longest === 0) {
            return 0.0;
        }

        return max(0.0, 1 - (levenshtein($a, $b) / $longest));
    }

    /**
     * How much of the two paths' leading structure agrees, 0–1.
     *
     * Leading, not any: `blog/2024/x` and `blog/2025/y` share their first segment and that is a
     * real signal, while `news/x` and `press/news` share a segment and mean nothing by it.
     *
     * @param string[] $a
     * @param string[] $b
     */
    public static function pathAffinity(array $a, array $b): float
    {
        $longest = max(count($a), count($b));

        if ($longest === 0) {
            return 1.0;
        }

        $shared = 0;

        foreach ($a as $index => $segment) {
            if (!isset($b[$index]) || strcasecmp($segment, $b[$index]) !== 0) {
                break;
            }

            $shared++;
        }

        return $shared / $longest;
    }

    /**
     * Blend the three per-dimension scores into one 0–100 number.
     *
     * Weights are normalised rather than validated into summing to 100, so an admin can set them
     * to 3/1/1 and mean it. All-zero weights fall back to an even split, because a rule that
     * scores everything zero is a rule that silently never fires.
     *
     * @param array<string, float> $scores dimension => 0–1
     * @param array<string, int|float> $weights dimension => relative weight
     */
    public static function blend(array $scores, array $weights): float
    {
        $total = 0.0;
        $weighted = 0.0;

        foreach ($scores as $dimension => $value) {
            $weight = (float)($weights[$dimension] ?? 0);

            if ($weight <= 0) {
                continue;
            }

            $total += $weight;
            $weighted += $weight * max(0.0, min(1.0, $value));
        }

        if ($total <= 0) {
            $values = array_values($scores);

            return $values ? round(100 * array_sum($values) / count($values), 1) : 0.0;
        }

        return round(100 * ($weighted / $total), 1);
    }

    private static function comparable(string $value): string
    {
        $value = strtolower(StringHelper::toAscii($value));

        return substr($value, 0, self::MAX_COMPARE_LENGTH);
    }
}
