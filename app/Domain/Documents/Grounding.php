<?php

namespace App\Domain\Documents;

use Carbon\CarbonImmutable;

/**
 * Checks that what the AI says a document contains is really there. Comparison ignores case, spacing
 * and punctuation (PDF text layers vary), but every letter and digit of the quote must appear in order.
 *
 * Amounts are the exception: ignoring punctuation would find the quote "£12.50" in "Crew fee £1,250". So an amount
 * is checked in the document's own words where the quote was found, commas and decimal points included, and the
 * whole number there counts ("£1" in "£1,250" is £1,250).
 */
final class Grounding
{
    private const FOLD = ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', 'ﬁ' => 'fi', 'ﬂ' => 'fl'];

    /** How many places in a document an amount is looked for (the same quote can appear more than once). */
    private const MAX_PLACES = 50;

    public static function normalise(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::FOLD);

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
     * Does the document state this amount where the quote is? Each place the quote is found (ignoring case, spacing
     * and punctuation, as quoteFound does) is read in the document's own words, widened to whole numbers at either
     * end, and the amount must be one of the numbers there, with its commas and decimal point as the document has
     * them.
     */
    public static function amountInText(?string $text, string $quote, int $minor): bool
    {
        $chars = preg_split('//u', self::normalise($quote), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($text === null || count($chars) < 2) {
            return false;
        }
        $folded = strtr(mb_strtolower($text), self::FOLD);
        $pattern = '/'.implode('[^\p{L}\p{N}]*', array_map(fn (string $c) => preg_quote($c, '/'), $chars)).'/u';
        $offset = 0;
        for ($i = 0; $i < self::MAX_PLACES && preg_match($pattern, $folded, $m, PREG_OFFSET_CAPTURE, $offset); $i++) {
            [$found, $start] = $m[0];
            if (in_array(abs($minor), self::amountsIn(self::cellsApart(self::wholeNumbers($folded, $start, $start + strlen($found)))), true)) {
                return true;
            }
            // On to the next place, one character on (the same words can overlap).
            $offset = $start + strlen(mb_substr($found, 0, 1));
        }

        return false;
    }

    /**
     * A PDF's text layer often runs a table's cells together ("2 x 450.00900.00"): an amount with pence running
     * straight into another whole amount with pence is read as two. A longer decimal ("1.459") is left as it is.
     */
    private static function cellsApart(string $span): string
    {
        for ($i = 0; $i < 10; $i++) {
            $apart = preg_replace('/(\d\.\d{2})(?=\d{1,3}(?:,\d{3})*\.\d{2}(?!\d))/', '$1 ', $span) ?? $span;
            if ($apart === $span) {
                break;
            }
            $span = $apart;
        }

        return $span;
    }

    /** The span [$start, $end) of $text (in bytes), widened so a number cut at either end is read whole. */
    private static function wholeNumbers(string $text, int $start, int $end): string
    {
        $digit = fn (int $at) => $at >= 0 && $at < strlen($text) && ctype_digit($text[$at]);
        $separator = fn (int $at) => $at >= 0 && $at < strlen($text) && ($text[$at] === ',' || $text[$at] === '.');
        while ($digit($start - 1) || ($separator($start - 1) && $digit($start - 2))) {
            $start--;
        }
        while ($digit($end) || ($separator($end) && $digit($end + 1))) {
            $end++;
        }

        return substr($text, $start, $end - $start);
    }

    /**
     * Does the quote state this date (as 14 March, Mar 14, 14/03/2025 or 2025-03-14)? Prices like £14.03 don't
     * count as dates, and when the quote gives a year it has to be the right one.
     */
    public static function dateInQuote(string $date, string $quote): bool
    {
        $d = CarbonImmutable::parse($date);
        $q = mb_strtolower(preg_replace('/[£$€]\s?\d[\d,]*(?:\.\d+)?/u', ' ', $quote));
        if (str_contains($q, $d->format('Y-m-d'))) {
            return true;
        }
        preg_match_all('/\b(?:19|20)\d{2}\b/', $q, $years);
        if ($years[0] && ! in_array($d->format('Y'), $years[0], true)) {
            return false;
        }
        $day = (int) $d->format('j');
        $month = (int) $d->format('n');
        preg_match_all('/\b(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2}|\d{4}))?\b/', $q, $numeric, PREG_SET_ORDER);
        foreach ($numeric as $m) {
            $yearOk = ! isset($m[3]) || $m[3] === '' || $m[3] === $d->format('Y') || $m[3] === $d->format('y');
            if ((int) $m[1] === $day && (int) $m[2] === $month && $yearOk) {
                return true;
            }
        }
        $name = mb_strtolower(substr($d->format('F'), 0, 3));

        return (bool) preg_match('/\b0?'.$day.'(st|nd|rd|th)?\s+(of\s+)?'.$name.'/', $q)
            || (bool) preg_match('/\b'.$name.'[a-z]*\.?,?\s+0?'.$day.'\b/', $q);
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
        if ($amountMinor !== null && ! self::amountInText($documentText, $quote, $amountMinor)) {
            return ['verified' => false, 'note' => 'The document gives a different figure where the quote is (check the commas and decimal point). Check before using.'];
        }

        return ['verified' => true, 'note' => null];
    }
}
