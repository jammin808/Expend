<?php

namespace App\Domain\Import;

use Carbon\CarbonImmutable;

/**
 * The details a statement line carries besides its date and amount: the time, the place, the card used and the kind
 * of payment, read from whichever columns the bank's export has (Amex's Town/City, Revolut's started time, a card
 * number column) or, for fixed-width card lines ("TESCO STORES 3021    LEEDS     GB"), from the line itself.
 */
final class LineDetails
{
    private const TIME_KEYS = ['time', 'transaction time', 'started date', 'completed date', 'date started (utc)', 'date completed (utc)', 'date started', 'date completed', 'transaction date', 'date', 'created', 'timestamp'];

    private const PLACE_KEYS = ['address', 'merchant address', 'town/city', 'town', 'city', 'merchant city', 'merchant town', 'location', 'merchant location', 'postcode', 'post code', 'country', 'merchant country'];

    private const CARD_KEYS = ['card no.', 'card number', 'card', 'card last four', 'card last 4', 'last 4 digits', 'card ending'];

    private const TYPE_KEYS = ['type', 'transaction type', 'payment type', 'method'];

    private const COUNTRIES = ['UNITED KINGDOM', 'GB', 'GBR', 'UK', 'GREAT BRITAIN'];

    /** @return array{time:?string, place:?string, card_last4:?string, payment_type:?string} */
    public static function from(array $raw, ?string $description = null, ?CarbonImmutable $date = null): array
    {
        $byKey = [];
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $byKey[mb_strtolower(trim($k))] = trim((string) $v);
            }
        }

        return [
            'time' => self::time($byKey, $date),
            'place' => self::place($byKey) ?? self::placeFromText((string) $description),
            'card_last4' => self::card($byKey),
            'payment_type' => self::type($byKey),
        ];
    }

    private static function time(array $row, ?CarbonImmutable $date): ?string
    {
        foreach (self::TIME_KEYS as $key) {
            $v = $row[$key] ?? '';
            if (! preg_match('/(?:^|[ T])(\d{1,2}):(\d{2})(?::\d{2})?/', $v, $m) || (int) $m[1] > 23) {
                continue;
            }
            if ($m[1] === '00' && $m[2] === '00' && ! str_contains($key, 'time')) {
                continue; // a date written with a midnight time has no real time
            }
            $time = sprintf('%02d:%s', (int) $m[1], $m[2]);
            if (str_contains($key, 'utc')) {
                $day = preg_match('/\d{4}-\d{2}-\d{2}/', $v, $d) ? $d[0] : $date?->format('Y-m-d');
                if ($day) {
                    return CarbonImmutable::parse("{$day} {$time}", 'UTC')->setTimezone('Europe/London')->format('H:i');
                }
            }

            return $time;
        }

        return null;
    }

    private static function place(array $row): ?string
    {
        $parts = [];
        foreach (self::PLACE_KEYS as $key) {
            $v = preg_replace('/\s+/', ' ', $row[$key] ?? '');
            if ($v === '' || in_array(mb_strtoupper($v), array_map('mb_strtoupper', $parts), true)) {
                continue;
            }
            $parts[] = $v;
        }
        $parts = array_values(array_filter($parts, fn ($p) => ! in_array(mb_strtoupper($p), self::COUNTRIES, true) || count($parts) === 1));
        if (! $parts) {
            return null;
        }

        return mb_substr(implode(', ', array_map(fn ($p) => self::title($p), $parts)), 0, 200);
    }

    /** "TESCO STORES 3021      LEEDS        GB": the town (and a country other than the UK) at the end of a card line. */
    private static function placeFromText(string $text): ?string
    {
        if (! preg_match('/\S\s{2,}([A-Z][A-Z \'.-]{2,28}?)\s{2,}([A-Z]{2,3})\s*$/', $text, $m)) {
            return null;
        }
        $town = trim($m[1]);
        $country = in_array($m[2], self::COUNTRIES, true) ? null : $m[2];

        return self::title($town).($country ? ', '.$country : '');
    }

    private static function card(array $row): ?string
    {
        foreach (self::CARD_KEYS as $key) {
            if (preg_match('/(\d{4})\D*$/', $row[$key] ?? '', $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private static function type(array $row): ?string
    {
        foreach (self::TYPE_KEYS as $key) {
            $v = $row[$key] ?? '';
            if ($v !== '' && mb_strlen($v) <= 40 && ! preg_match('/^\d/', $v)) {
                return self::title(str_replace('_', ' ', $v));
            }
        }

        return null;
    }

    private static function title(string $s): string
    {
        // Postcodes and short codes stay as they are; words become "Title Case".
        if (preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', $s) || mb_strlen($s) <= 3) {
            return mb_strtoupper($s);
        }

        return mb_strtoupper($s) === $s ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : $s;
    }
}
