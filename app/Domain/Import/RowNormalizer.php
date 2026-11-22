<?php

namespace App\Domain\Import;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Applies an import profile to raw CSV rows. Collects per-row problems instead of guessing. */
final class RowNormalizer
{
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

        return new NormalizedRow(
            postedOn: $posted,
            transactionOn: $txDate,
            description: mb_substr($description, 0, 500),
            counterparty: $counterparty,
            reference: $p->referenceColumn ? (trim($row[$p->referenceColumn] ?? '') ?: null) : null,
            amountPence: $amount,
            balanceAfterPence: $p->balanceColumn ? Money::parse($row[$p->balanceColumn] ?? '') : null,
            originalAmountMinor: $foreign ? ($amount < 0 ? -abs($original) : abs($original)) : null,
            originalCurrency: $foreign ? $currency : null,
            bankCategory: $p->categoryColumn ? (trim($row[$p->categoryColumn] ?? '') ?: null) : null,
            raw: $row,
            line: $line,
        );
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
