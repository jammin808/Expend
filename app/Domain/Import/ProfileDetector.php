<?php

namespace App\Domain\Import;

/**
 * Recognises Starling, Amex and Capital One exports by their headers, and makes a best guess for anything else.
 * The guess is always shown to you to check before importing.
 */
final class ProfileDetector
{
    public static function detect(array $headers, ?string $accountKind = null): ImportProfile
    {
        $has = fn (string ...$names) => collect($names)->every(fn ($n) => self::find($headers, [$n]) !== null);

        if ($has('Counter Party', 'Amount (GBP)')) {
            return new ImportProfile(
                preset: 'starling',
                dateColumn: self::find($headers, ['Date']),
                descriptionColumns: array_values(array_filter([self::find($headers, ['Reference'])])),
                counterpartyColumn: self::find($headers, ['Counter Party']),
                referenceColumn: self::find($headers, ['Reference']),
                amountColumn: self::find($headers, ['Amount (GBP)']),
                balanceColumn: self::find($headers, ['Balance (GBP)']),
                categoryColumn: self::find($headers, ['Spending Category']),
                dateFormat: 'd/m/Y',
            );
        }

        if ($has('Date', 'Description', 'Amount') && (self::find($headers, ['Appears On Your Statement As', 'Extended Details', 'Card Member']) !== null)) {
            return new ImportProfile(
                preset: 'amex',
                dateColumn: self::find($headers, ['Date']),
                descriptionColumns: [self::find($headers, ['Description'])],
                referenceColumn: self::find($headers, ['Reference']),
                amountColumn: self::find($headers, ['Amount']),
                invertSign: true,
                categoryColumn: self::find($headers, ['Category']),
                dateFormat: 'd/m/Y',
            );
        }

        $debit = self::find($headers, ['Debit', 'Money Out', 'Paid out', 'Debit Amount', 'Out']);
        $credit = self::find($headers, ['Credit', 'Money In', 'Paid in', 'Credit Amount', 'In']);
        $amount = self::find($headers, ['Amount', 'Amount (GBP)', 'Value', 'Transaction Amount', 'Billing Amount']);
        $posted = self::find($headers, ['Posted Date', 'Posting Date']);
        $txDate = self::find($headers, ['Transaction Date', 'Trans. Date']);
        $date = $posted ?? $txDate ?? self::find($headers, ['Date', 'Booking Date', 'Completed Date']);

        $profile = new ImportProfile(
            preset: 'custom',
            dateColumn: $date,
            transactionDateColumn: ($posted && $txDate) ? $txDate : null,
            descriptionColumns: array_values(array_filter([self::find($headers, ['Description', 'Details', 'Merchant', 'Narrative', 'Payee', 'Name', 'Transaction Description'])])),
            referenceColumn: self::find($headers, ['Reference', 'Ref']),
            amountColumn: ($debit && $credit) ? null : $amount,
            debitColumn: ($debit && $credit) ? $debit : null,
            creditColumn: ($debit && $credit) ? $credit : null,
            // Card exports with a single amount column usually show purchases as positive.
            invertSign: $accountKind === 'credit_card' && ! ($debit && $credit),
            balanceColumn: self::find($headers, ['Balance', 'Running Balance', 'Balance (GBP)']),
            categoryColumn: self::find($headers, ['Category', 'Spending Category']),
            currencyColumn: self::find($headers, ['Currency', 'Original Currency', 'Local Currency']),
            originalAmountColumn: self::find($headers, ['Original Amount', 'Local Amount', 'Foreign Amount']),
            dateFormat: 'd/m/Y',
        );

        if (self::find($headers, ['Card No.', 'Card Number']) !== null && $accountKind === 'credit_card') {
            $profile->preset = 'capital_one';
        }

        return $profile;
    }

    /** Case-insensitive header lookup. */
    public static function find(array $headers, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($headers as $header) {
                if (strcasecmp(trim($header), $candidate) === 0) {
                    return $header;
                }
            }
        }

        return null;
    }
}
