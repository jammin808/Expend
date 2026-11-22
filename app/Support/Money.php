<?php

namespace App\Support;

use InvalidArgumentException;

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

        $text = str_replace(['£', '$', '€', ',', ' ', "\u{00A0}"], '', $text);
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

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $m)) {
            throw new InvalidArgumentException("Can't read the amount \"{$value}\".");
        }

        $pence = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $negative ? -$pence : $pence;
    }

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
