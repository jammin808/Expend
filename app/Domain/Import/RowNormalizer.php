<?php

namespace App\Domain\Import;

use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Applies an import profile to raw CSV rows. Collects per-row problems instead of guessing. */
final class RowNormalizer
{
    /**
     * Currency names as card statements spell them out (Amex: "Foreign Spend Amount: 90.00 US DOLLARS"). Only names
     * known for sure: anything else is left without a currency rather than guessed.
     */
    private const CURRENCY_NAMES = [
        'US DOLLAR' => 'USD', 'U S DOLLAR' => 'USD', 'UNITED STATES DOLLAR' => 'USD', 'EURO' => 'EUR', 'SWISS FRANC' => 'CHF',
        'CANADIAN DOLLAR' => 'CAD', 'AUSTRALIAN DOLLAR' => 'AUD', 'NEW ZEALAND DOLLAR' => 'NZD', 'HONG KONG DOLLAR' => 'HKD',
        'SINGAPORE DOLLAR' => 'SGD', 'JAPANESE YEN' => 'JPY', 'YEN' => 'JPY', 'DANISH KRONE' => 'DKK', 'DANISH KRONER' => 'DKK',
        'NORWEGIAN KRONE' => 'NOK', 'NORWEGIAN KRONER' => 'NOK', 'SWEDISH KRONA' => 'SEK', 'SWEDISH KRONOR' => 'SEK',
        'ICELANDIC KRONA' => 'ISK', 'POLISH ZLOTY' => 'PLN', 'CZECH KORUNA' => 'CZK', 'HUNGARIAN FORINT' => 'HUF',
        'UAE DIRHAM' => 'AED', 'UNITED ARAB EMIRATES DIRHAM' => 'AED', 'SOUTH AFRICAN RAND' => 'ZAR', 'MEXICAN PESO' => 'MXN',
        'TURKISH LIRA' => 'TRY', 'BAHRAINI DINAR' => 'BHD', 'ISRAELI SHEKEL' => 'ILS', 'ISRAELI NEW SHEKEL' => 'ILS',
        'INDIAN RUPEE' => 'INR', 'CHINESE YUAN' => 'CNY', 'THAI BAHT' => 'THB',
    ];

    /** @return array{rows: list<NormalizedRow>, errors: list<string>} */
    public static function normalize(ImportProfile $profile, array $rows): array
    {
        $out = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // header is line 1
            try {
                $out[] = self::one($profile, $row, $line);
            } catch (InvalidArgumentException $e) {
                $errors[] = "Line {$line}: {$e->getMessage()}";
            }
        }

        return ['rows' => $out, 'errors' => $errors];
    }

    private static function one(ImportProfile $p, array $row, int $line): NormalizedRow
    {
        $posted = self::date($row[$p->dateColumn] ?? '', $p->dateFormat);
        $txDate = $p->transactionDateColumn && ($row[$p->transactionDateColumn] ?? '') !== ''
            ? self::date($row[$p->transactionDateColumn], $p->dateFormat) : null;

        if ($p->amountColumn) {
            $amount = Money::parse($row[$p->amountColumn] ?? '');
            if ($amount === null) {
                throw new InvalidArgumentException('no amount.');
            }
        } else {
            $out = Money::parse($row[$p->debitColumn] ?? '') ?? 0;
            $in = Money::parse($row[$p->creditColumn] ?? '') ?? 0;
            $amount = abs($in) - abs($out);
        }
        if ($p->invertSign) {
            $amount = -$amount;
        }
        if ($amount === 0) {
            throw new InvalidArgumentException('the amount is zero.');
        }

        $counterparty = $p->counterpartyColumn ? (trim($row[$p->counterpartyColumn] ?? '') ?: null) : null;
        $parts = array_map(fn ($c) => trim($row[$c] ?? ''), $p->descriptionColumns);
        if ($counterparty !== null) {
            array_unshift($parts, $counterparty);
        }
        $description = trim(implode(' ', array_unique(array_filter($parts))));
        if ($description === '') {
            throw new InvalidArgumentException('no description.');
        }

        $currency = $p->currencyColumn ? strtoupper(trim($row[$p->currencyColumn] ?? '')) : '';
        $original = $p->originalAmountColumn ? Money::parse($row[$p->originalAmountColumn] ?? '') : null;
        $foreign = $currency !== '' && $currency !== 'GBP' && $original !== null;
        if ($foreign) {
            $original = Money::fromHundredths($original, $currency); // ¥1,500 is 1500, not 150000
        } elseif ($spend = self::foreignSpend($row)) {
            // A card that writes the foreign amount into the line's details (Amex) rather than a column of its own.
            [$foreign, $currency, $original] = [true, $spend['currency'], $spend['minor']];
        }

        // The rate the bank's sterling figure works out at (¥1,500 for £8.00 is 187.5 per £1), kept with the line as
        // a Revolut line's is, so it's shown and exported in the currency's own units.
        $rate = $foreign && $original ? self::rate($original, $currency, $amount) : null;

        return new NormalizedRow(
            postedOn: $posted,
            transactionOn: $txDate,
            description: mb_substr($description, 0, 500),
            counterparty: $counterparty,
            reference: $p->referenceColumn ? (trim($row[$p->referenceColumn] ?? '') ?: null) : null,
            amountPence: $amount,
            balanceAfterPence: self::balance($p, $row),
            originalAmountMinor: $foreign ? ($amount < 0 ? -abs($original) : abs($original)) : null,
            originalCurrency: $foreign ? $currency : null,
            bankCategory: $p->categoryColumn ? (trim($row[$p->categoryColumn] ?? '') ?: null) : null,
            raw: $row,
            line: $line,
            fxUnitsPerGbp: $rate,
            fxRateDate: $rate ? ($txDate ?? $posted)->format('Y-m-d') : null,
            fxSource: $rate ? 'bank' : null,
        );
    }

    /** How many of the currency £1 bought: the amount in the currency's main units over the pounds, to 6 places. */
    public static function rate(int $minor, string $currency, int $pence): ?string
    {
        if ($pence === 0) {
            return null;
        }
        $units = BigDecimal::ofUnscaledValue(abs($minor), Money::digits($currency));

        return (string) $units->multipliedBy(100)->dividedBy(abs($pence), 6, RoundingMode::HALF_UP);
    }

    /**
     * The amount spent in another currency, where a card writes it into the line's details instead of a column of its
     * own: Amex's "Extended Details" reads "Foreign Spend Amount: 90.00 US DOLLARS Commission Amount: 1.94 Currency
     * Exchange Rate: 1.3127". Null when there's none, or when the currency or amount can't be read for sure.
     *
     * @return array{currency:string, minor:int}|null the amount in the currency's own minor units, unsigned
     */
    public static function foreignSpend(array $raw): ?array
    {
        $details = null;
        foreach ($raw as $key => $value) {
            if (is_string($key) && strcasecmp(trim($key), 'Extended Details') === 0 && is_scalar($value)) {
                $details = preg_replace('/\s+/', ' ', (string) $value);
            }
        }
        if (! $details || ! preg_match('/Foreign Spend Amount:\s*([\d,]+(?:\.\d+)?)\s+([A-Za-z][A-Za-z .]*?)\s*(?=Commission Amount|Currency Exchange Rate|$)/i', $details, $m)) {
            return null;
        }
        $name = trim(preg_replace('/[^A-Z ]/', ' ', strtoupper($m[2])));
        $name = preg_replace('/\s+/', ' ', $name);
        $code = self::CURRENCY_NAMES[$name] ?? self::CURRENCY_NAMES[preg_replace('/S$/', '', $name)] ?? null;
        if (! $code && preg_match('/^[A-Z]{3}$/', $name)) {
            try {
                $code = Currency::of($name)->getCurrencyCode();
            } catch (\Throwable) {
                $code = null;
            }
        }
        if (! $code || $code === 'GBP') {
            return null;
        }
        try {
            $minor = BigDecimal::of(str_replace(',', '', $m[1]))->toScale(Money::digits($code))->getUnscaledValue()->toInt();
        } catch (\Throwable) {
            return null;
        }

        return $minor > 0 ? ['currency' => $code, 'minor' => $minor] : null;
    }

    private static function balance(ImportProfile $p, array $row): ?int
    {
        $balance = $p->balanceColumn ? Money::parse($row[$p->balanceColumn] ?? '') : null;

        // When amounts are flipped (card exports), the balance is flipped too so the running check still adds up.
        return ($balance !== null && $p->invertSign) ? -$balance : $balance;
    }

    public static function date(string $value, string $format): CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('no date.');
        }
        // Some exports add a time ("01/04/2025 14:03"); keep the date part.
        $datePart = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $value);
        $native = \DateTimeImmutable::createFromFormat('!'.$format, $datePart);
        $problems = \DateTimeImmutable::getLastErrors();
        if ($native === false || ($problems && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            throw new InvalidArgumentException("can't read the date \"{$value}\" as {$format}.");
        }

        return CarbonImmutable::instance($native)->startOfDay();
    }
}
