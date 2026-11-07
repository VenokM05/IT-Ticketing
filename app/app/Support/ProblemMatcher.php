<?php

namespace App\Support;

/**
 * Text matching for the Problem Tally's fuzzy repeat detection. Subjects are
 * reduced to lower-cased alphanumeric token sets and two complaints count as
 * the same problem when their word overlap (Jaccard similarity: intersection
 * over union) clears the configured threshold — catching "printer jam on 3rd
 * floor" vs "printer jams on 3rd floor" that exact matching would miss.
 *
 * Pure static helpers so ReportService's PHP-side aggregation stays dialect
 * independent (identical on sqlite and MySQL) and the rules unit-testable on
 * their own.
 */
class ProblemMatcher
{
    /**
     * Unique lower-cased tokens: punctuation and separators flatten to spaces,
     * letters and numbers (any script) survive. A blank or symbol-only subject
     * yields no tokens, which keeps empty subjects from collapsing into one
     * bogus "recurring problem".
     *
     * @return list<string>
     */
    public static function tokens(?string $text): array
    {
        $clean = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim((string) $text));

        return array_values(array_unique(array_filter(
            explode(' ', strtolower($clean)),
            fn (string $word) => $word !== '',
        )));
    }

    /**
     * The flat match key used for the exact-repeat tier: tokens rejoined by a
     * single space, so case, punctuation and stray whitespace never split a
     * group. Blank subjects normalise to ''.
     */
    public static function normalized(?string $text): string
    {
        return implode(' ', static::tokens($text));
    }

    /**
     * Jaccard similarity of two token sets, 0.0 (nothing in common) to
     * 1.0 (identical). Either side empty scores 0.0 — never a match.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }
}
