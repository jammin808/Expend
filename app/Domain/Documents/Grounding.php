<?php

namespace App\Domain\Documents;

/**
 * Checks that what the AI says a document contains is really there. Comparison ignores case, spacing
 * and punctuation (PDF text layers vary), but every letter and digit of the quote must appear in order.
 */
final class Grounding
{
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', 'ﬁ' => 'fi', 'ﬂ' => 'fl']);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
    }

    /** Is the quote present in the text? */
    public static function quoteFound(?string $text, string $quote): bool
    {
        $q = self::normalise($quote);
        if ($text === null || mb_strlen($q) < 2) {
            return false;
        }

        return str_contains(self::normalise($text), $q);
    }

    /** Every money-looking number in a piece of text, in minor units. */
    public static function amountsIn(string $text): array
    {
        preg_match_all('/(?<![\d.])(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?(?![\d])/', $text, $m, PREG_SET_ORDER);

        return array_values(array_unique(array_map(function ($match) {
            $whole = (int) str_replace(',', '', $match[1]);
            $frac = isset($match[2]) ? (int) str_pad($match[2], 2, '0') : 0;

            return $whole * 100 + $frac;
        }, $m)));
    }

    /** Does this amount appear in the quote it supposedly came from? */
    public static function amountInQuote(int $minor, string $quote): bool
    {
        return in_array(abs($minor), self::amountsIn($quote), true);
    }

    /**
     * @return array{verified:bool, note:?string}
     */
    public static function check(?string $documentText, string $textStatus, string $quote, ?int $amountMinor = null): array
    {
        if ($textStatus !== 'extracted') {
            return ['verified' => false, 'note' => 'Read from an image or scan, so it can’t be checked word for word. Check it against the document.'];
        }
        if (! self::quoteFound($documentText, $quote)) {
            return ['verified' => false, 'note' => 'The quoted words weren’t found in the document. Check before using.'];
        }
        if ($amountMinor !== null && ! self::amountInQuote($amountMinor, $quote)) {
            return ['verified' => false, 'note' => 'The amount isn’t in the quoted words. Check before using.'];
        }

        return ['verified' => true, 'note' => null];
    }
}
