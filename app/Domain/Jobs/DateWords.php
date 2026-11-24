<?php

namespace App\Domain\Jobs;

/**
 * Days written in words, as UK crew emails write them: "12-14 March", "Thurs 12th - Sat 14th March", "Thursday 12
 * March to Saturday 14 March", "30 March - 2 April", "12th & 13th March", "12, 13 and 14 March", "12th/13th March",
 * "Sat 6th & Sun 7th June", "March 12-14", "14 March 2027", "14/03/2027". A day name in front of a day ("Sat 14th")
 * is allowed anywhere a day is.
 *
 * One reader for the rules that spot work (OpportunityRules::dates) and for checking that a quote gives a date
 * (Opportunities::dateCovered), so the two always agree on what a phrase means. It only reads the words: the year of
 * a date that doesn't give one is worked out by the caller.
 */
final class DateWords
{
    public const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    /** A day's name, written out or short ("Thurs", "Weds", "Sat."). */
    private const WEEKDAY = '(?:mon(?:day)?|tue(?:s(?:day)?)?|wed(?:s|nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)';

    /**
     * Every date or run of dates in the text, in the order they're found, none overlapping another.
     *
     * @return list<array{at: int, len: int, kind: string, parts: list<array{0: array{0: int, 1: int, 2: ?int}, 1: array{0: int, 1: int, 2: ?int}}>}>
     *                                                                                                                                                each part is a first and last day (day, month, year or null); a list of days gives one part per day
     */
    public static function spans(string $text): array
    {
        $mon = '('.self::MONTHS.')\.?';
        $wd = '(?:'.self::WEEKDAY.'\.?,?\s+)?(?:the\s+)?';
        $th = '(?:st|nd|rd|th)?';
        $d = '(?<![\d£$€:.\/])(\d{1,2})'.$th;
        $dash = '\s*(?:-|–|—|to|until|till|thru|through)\s*';
        $of = '\s+(?:of\s+)?';
        $year = '(?:,?\s+((?:19|20)\d{2}))?';
        $sep = '(?:\s*,\s*(?:and\s+)?|\s*&\s*|\s+and\s+|\s*\/\s*|\s*\+\s*)';
        $patterns = [
            'cross' => '/\b'.$wd.$d.$of.$mon.',?'.$dash.$wd.'(\d{1,2})'.$th.$of.$mon.$year.'\b/iu',
            'range' => '/\b'.$wd.$d.$dash.$wd.'(\d{1,2})'.$th.$of.$mon.$year.'\b/iu',
            'list' => '/\b'.$wd.$d.'((?:'.$sep.$wd.'\d{1,2}'.$th.')+)'.$of.$mon.$year.'\b/iu',
            'single' => '/\b'.$wd.$d.$of.$mon.$year.'\b/iu',
            'us' => '/\b(?:'.self::WEEKDAY.'\.?,?\s+)?'.$mon.'\s+(\d{1,2})'.$th.'(?![:.]\d)(?:'.$dash.'(\d{1,2})'.$th.'(?![:.]\d))?'.$year.'\b/iu',
            'numeric' => '/\b(\d{1,2})\/(\d{1,2})\/((?:20)?\d{2})\b/',
        ];

        $found = [];
        foreach ($patterns as $kind => $p) {
            if (! preg_match_all($p, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($all as $m) {
                $at = $m[0][1];
                $len = strlen($m[0][0]);
                foreach ($found as $f) {
                    if ($at < $f['at'] + $f['len'] && $at + $len > $f['at']) {
                        continue 2; // part of a date already read ("12-14 March" isn't also "14 March")
                    }
                }
                $v = fn (int $i) => isset($m[$i]) && $m[$i][1] >= 0 && $m[$i][0] !== '' ? $m[$i][0] : null;
                $y = fn (?string $s) => $s === null ? null : (strlen($s) === 2 ? 2000 + (int) $s : (int) $s);
                if ($kind === 'us' && $v(1) === 'may') {
                    continue; // "you may 2…" isn't a date; "May 2" is
                }
                $parts = match ($kind) {
                    'cross' => [[[(int) $v(1), self::month($v(2)), $y($v(5))], [(int) $v(3), self::month($v(4)), $y($v(5))]]],
                    'range' => [[[(int) $v(1), self::month($v(3)), $y($v(4))], [(int) $v(2), self::month($v(3)), $y($v(4))]]],
                    'list' => self::listParts((int) $v(1), (string) $v(2), self::month($v(3)), $y($v(4)), substr($text, max(0, $at - 12), min(12, $at))),
                    'single' => [[[(int) $v(1), self::month($v(2)), $y($v(3))], [(int) $v(1), self::month($v(2)), $y($v(3))]]],
                    'us' => [[[(int) $v(2), self::month($v(1)), $y($v(4))], [(int) ($v(3) ?? $v(2)), self::month($v(1)), $y($v(4))]]],
                    'numeric' => [[[(int) $v(1), (int) $v(2), $y($v(3))], [(int) $v(1), (int) $v(2), $y($v(3))]]],
                };
                // "30 December - 2 January 2027": the first day is in the year before.
                if ($kind === 'cross' && $parts[0][0][2] !== null && $parts[0][0][1] > $parts[0][1][1]) {
                    $parts[0][0][2]--;
                }
                $parts = array_values(array_filter($parts, fn ($p) => self::valid($p[0]) && self::valid($p[1])));
                if ($parts === []) {
                    continue;
                }
                $found[] = ['at' => $at, 'len' => $len, 'kind' => $kind, 'parts' => $parts];
            }
        }
        usort($found, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $found;
    }

    /** "12, 13 and 14 March" is three days; "between 12 and 14 March" is a run of days. */
    private static function listParts(int $first, string $rest, ?int $month, ?int $year, string $before): array
    {
        preg_match_all('/(\d{1,2})(?:st|nd|rd|th)?/', $rest, $m);
        $days = [$first, ...array_map('intval', $m[1])];
        if (count($days) === 2 && preg_match('/\bbetween\s+(?:the\s+)?$/i', $before)) {
            return [[[$days[0], $month, $year], [$days[1], $month, $year]]];
        }

        return array_map(fn (int $day) => [[$day, $month, $year], [$day, $month, $year]], $days);
    }

    /** @param array{0: int, 1: ?int, 2: ?int} $d */
    private static function valid(array $d): bool
    {
        return $d[1] !== null && $d[1] >= 1 && $d[1] <= 12 && $d[0] >= 1 && checkdate($d[1], $d[0], $d[2] ?? 2000);
    }

    public static function month(?string $word): ?int
    {
        $i = array_search(mb_strtolower(substr((string) $word, 0, 3)), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);

        return $i === false ? null : $i + 1;
    }
}
