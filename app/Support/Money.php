<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Money\Currency;
use InvalidArgumentException;
use Throwable;

/**
 * Money is always held as integer minor units (pence for GBP). Never floats.
 */
final class Money
{
    private const SYMBOLS = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];

    /**
     * Parse a human or bank-formatted amount ("£1,234.56", "-12.30", "(4.50)", "12.3", "1.234,56" is not supported)
     * into integer minor units. Throws on anything ambiguous rather than guessing.
     */
    public static function parse(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }

        $text = str_replace(['£', '$', '€', ' ', "\u{00A0}"], '', $text);
        $text = preg_replace('/^(GBP|USD|EUR)/i', '', $text);

        if (preg_match('/^(.*?)(CR|DR)$/i', $text, $m)) {
            $text = $m[1];
            if (strtoupper($m[2]) === 'DR') {
                $negative = ! $negative;
            }
        }

        if (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        } elseif (str_starts_with($text, '-') || str_starts_with($text, "\u{2212}")) {
            $negative = ! $negative;
            $text = ltrim(substr($text, str_starts_with($text, '-') ? 1 : 3));
        }

        // Commas are only accepted as thousands separators (1,234.56). A decimal comma (12,50) is refused, not guessed.
        if (! preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/', $text, $m)) {
            throw new InvalidArgumentException("Can't read the amount \"{$value}\". Use a full stop for pence, like 12.50.");
        }

        $pence = ((int) str_replace(',', '', $m[1])) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $negative ? -$pence : $pence;
    }

    /**
     * Like parse(), but into the currency's own minor units: "17,500" yen is 17500, "12.345" dinars is 12345, "12.30"
     * pounds is 1230. More decimal places than the currency has are refused unless they're zeros ("17,500.00" yen).
     */
    public static function parseIn(string|int|float|null $value, ?string $currency): ?int
    {
        $digits = self::digits($currency);
        if ($digits === 2 || $value === null || is_int($value)) {
            $hundredths = self::parse($value);

            return $hundredths === null ? null : self::fromHundredths($hundredths, $currency);
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }
        $text = str_replace(['£', '$', '€', '¥', ' ', "\u{00A0}"], '', $text);
        $text = preg_replace('/^[A-Z]{3}/i', '', $text) ?? $text;
        if (preg_match('/^(.*?)(CR|DR)$/i', $text, $m)) {
            $text = $m[1];
            if (strtoupper($m[2]) === 'DR') {
                $negative = ! $negative;
            }
        }
        if (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        } elseif (str_starts_with($text, '-') || str_starts_with($text, "\u{2212}")) {
            $negative = ! $negative;
            $text = ltrim(substr($text, str_starts_with($text, '-') ? 1 : 3));
        }
        if (! preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?$/', $text, $m)) {
            throw new InvalidArgumentException("Can't read the amount \"{$value}\". Use a full stop before any decimals, like 1,234.5.");
        }
        $decimals = rtrim($m[2] ?? '', '0');
        if (strlen($decimals) > $digits) {
            throw new InvalidArgumentException("\"{$value}\" has more decimal places than ".strtoupper((string) $currency).' has ('.$digits.').');
        }
        $minor = ((int) str_replace(',', '', $m[1])) * (10 ** $digits) + (int) str_pad($decimals, $digits, '0');

        return $negative ? -$minor : $minor;
    }

    /**
     * How many decimal places a currency has (its ISO 4217 minor units): GBP 2, JPY 0, KWD 3. An unknown code gets 2.
     * resources/js/lib/format.ts holds the same list for the pages (a test checks they agree).
     */
    public static function digits(?string $currency): int
    {
        static $known = [];
        $code = strtoupper(trim((string) $currency));
        if ($code === '' || $code === 'GBP') {
            return 2;
        }

        return $known[$code] ??= (static function () use ($code): int {
            try {
                return Currency::of($code)->getDefaultFractionDigits();
            } catch (Throwable) {
                return 2;
            }
        })();
    }

    /**
     * An amount in hundredths of the currency's main unit, whatever the currency: pence, cents, and hundredths of a yen
     * for an invoice, PO or booking in yen (what parse() gives: invoice and PO lines, bookings, every sterling
     * figure). Always two decimal places, as invoices and POs are written.
     *
     * An amount in a currency's own minor units (an original amount, a currency pocket's balance: ¥1,500 is 1500) is
     * formatMinor()'s, not this.
     */
    public static function format(?int $minor, string $currency = 'GBP', bool $signed = true): string
    {
        if ($minor === null) {
            return '';
        }

        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';
        $abs = abs($minor);
        $text = $symbol.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($minor < 0 && $signed) ? "\u{2212}".$text : $text;
    }

    /**
     * An amount in the currency's own minor units (ISO 4217: pence, cents, whole yen, fils), the way a line's original
     * amount (original_amount_minor), a currency pocket's balance (balance_after_minor) and a manual expense's original
     * amount are stored: formatMinor(1500, 'JPY') is "JPY 1,500", formatMinor(12345, 'KWD') is "KWD 12.345". For
     * two-decimal currencies it's the same as format().
     */
    public static function formatMinor(?int $minor, ?string $currency = 'GBP', bool $signed = true): string
    {
        if ($minor === null) {
            return '';
        }

        $currency = strtoupper(trim((string) $currency)) ?: 'GBP';
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';
        $digits = self::digits($currency);
        $unit = 10 ** $digits;
        $abs = abs($minor);
        $text = $symbol.number_format(intdiv($abs, $unit)).($digits > 0 ? '.'.str_pad((string) ($abs % $unit), $digits, '0', STR_PAD_LEFT) : '');

        return ($minor < 0 && $signed) ? "\u{2212}".$text : $text;
    }

    /**
     * An amount held in hundredths of the currency's main unit (what parse() returns whatever the currency: a
     * statement's "Local amount" column, a statement balance typed in), in the currency's own minor units, the way
     * original amounts and pocket balances are stored. Rounds to the nearest minor unit, halves away from zero. For a
     * currency with two decimal places it's unchanged.
     */
    public static function fromHundredths(?int $hundredths, ?string $currency): ?int
    {
        if ($hundredths === null) {
            return null;
        }
        $digits = self::digits($currency);
        if ($digits === 2) {
            return $hundredths;
        }
        if ($digits > 2) {
            return $hundredths * (10 ** ($digits - 2));
        }
        $div = 10 ** (2 - $digits);
        $whole = intdiv(abs($hundredths) + intdiv($div, 2), $div);

        return $hundredths < 0 ? -$whole : $whole;
    }

    /**
     * The other way: an amount in the currency's own minor units, in hundredths of its main unit, for something that
     * holds hundredths whatever the currency (a to-do item's amount, shown with format()). ¥1,500 (1500) is 150000;
     * KWD 12.345 is 1235 (to the nearest hundredth, halves away from zero).
     */
    public static function toHundredths(?int $minor, ?string $currency): ?int
    {
        if ($minor === null) {
            return null;
        }
        $digits = self::digits($currency);
        if ($digits <= 2) {
            return $minor * (10 ** (2 - $digits));
        }
        $div = 10 ** ($digits - 2);
        $whole = intdiv(abs($minor) + intdiv($div, 2), $div);

        return $minor < 0 ? -$whole : $whole;
    }

    /**
     * A stored exchange rate without its trailing zeros ("1.150000" is "1.15"; "190" stays "190", whatever the
     * database gives back), or null when there's none.
     */
    public static function rate(mixed $rate): ?string
    {
        if ($rate === null || trim((string) $rate) === '') {
            return null;
        }
        try {
            return (string) BigDecimal::of(trim((string) $rate))->strippedOfTrailingZeros();
        } catch (MathException) {
            return (string) $rate;
        }
    }

    /** An amount in the currency's own minor units as a number of main units (for a spreadsheet cell): ¥1,500 → 1500.0. */
    public static function major(int $minor, ?string $currency): float
    {
        $digits = self::digits($currency);

        return round($minor / (10 ** $digits), $digits);
    }

    /**
     * Split an amount into a business part and a remainder, using whole pence.
     * The remainder penny always goes to the second (personal) part, so the parts sum exactly.
     *
     * @return array{0:int,1:int}
     */
    public static function splitByPercent(int $amount, int $percent): array
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Percent must be between 0 and 100.');
        }

        $business = intdiv($amount * $percent, 100);

        return [$business, $amount - $business];
    }
}
