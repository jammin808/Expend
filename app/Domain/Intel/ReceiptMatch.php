<?php

namespace App\Domain\Intel;

use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which receipts go with which payments (the method receipt.match, docs/intelligence-cycle.md section 2.4): a receipt
 * read from an email or an upload goes with a payment of the same amount, dated within DATE_DAYS days of the date on
 * it, or (a receipt with no date on it) received within RECEIVED_DAYS days of the payment. A receipt is often
 * forwarded long after the payment (at the month's end, at tax time), so when it arrived doesn't matter, as long as
 * it wasn't more than RECEIVED_DAYS days before the payment.
 *
 * A receipt's amount is in hundredths of its currency (as read); a payment's other currencies (an account's own, and
 * the one the shop charged in) are kept in each currency's own units, so they're put in hundredths to compare.
 *
 * The transaction page (TransactionController::links(), one payment) and the detectors (receipt.unmatched,
 * cost.no_receipt: every payment and receipt at once) use this one rule, so they always agree. Only reads.
 */
final class ReceiptMatch
{
    public const METHOD = 'receipt.match';

    /** A receipt's own date is at most this many days from the payment's. */
    public const DATE_DAYS = 4;

    /** A receipt with no date on it arrived at most this many days from the payment; and none arrived this long before it. */
    public const RECEIVED_DAYS = 10;

    /** The most receipts of one amount the transaction page looks at for one payment. */
    public const PAGE_LIMIT = 200;

    /** The columns of a receipt the match reads. */
    private const COLUMNS = ['id', 'parent_id', 'original_name', 'email_subject', 'extraction', 'received_at', 'sha256'];

    /**
     * The amounts a receipt for this payment could show, in hundredths: what left the account, and the same in the
     * account's own currency and in the currency the shop charged.
     *
     * @return list<int>
     */
    public static function amountsOf(BankTransaction|ManualExpense $item): array
    {
        $merchant = $item instanceof BankTransaction && $item->merchant_amount_minor ? Money::toHundredths(abs((int) $item->merchant_amount_minor), $item->merchant_currency) : null;

        return array_values(array_unique(array_filter([abs((int) $item->amount_pence),
            $item->original_amount_minor ? Money::toHundredths(abs((int) $item->original_amount_minor), $item->original_currency) : null,
            $merchant])));
    }

    /** The day a payment was made, as a receipt would date it. */
    public static function dateOf(BankTransaction|ManualExpense $item): CarbonImmutable
    {
        return CarbonImmutable::parse(($item instanceof BankTransaction ? $item->effectiveDate() : $item->spent_on)->format('Y-m-d'));
    }

    /**
     * The receipts for one payment, as the transaction page lists them: read from email or uploads, for one of its
     * amounts, oldest arrival first.
     *
     * @return Collection<int, Document>
     */
    public static function forPayment(BankTransaction|ManualExpense $item): Collection
    {
        $amounts = self::amountsOf($item);
        $date = self::dateOf($item);
        if ($amounts === []) {
            return collect();
        }

        return Document::query()->where('kind', 'receipt')->whereNotNull('extraction')
            ->whereIn('extraction->receipt->amount_minor', array_merge($amounts, array_map('strval', $amounts)))
            ->where(fn ($q) => $q->whereNull('received_at')->orWhere('received_at', '>=', $date->subDays(self::RECEIVED_DAYS)->format('Y-m-d 00:00:00')))
            ->orderBy('received_at')->orderBy('id')->limit(self::PAGE_LIMIT)->get(self::COLUMNS)
            ->filter(fn (Document $d) => self::matches($d, $amounts, $date))->values();
    }

    /**
     * Every receipt with an amount read from it, for matching many payments at once (the detectors): the same
     * receipts forPayment() looks among. With $kind 'invoice': a supplier's invoice whose amount and date were read
     * the way a receipt's are (cost.no_receipt takes it as the receipt; the transaction page lists receipts only).
     *
     * @return Collection<int, Document>
     */
    public static function receipts(string $kind = 'receipt'): Collection
    {
        return Document::query()->where('kind', $kind)->whereNotNull('extraction')->orderBy('received_at')->orderBy('id')->get(self::COLUMNS)
            ->filter(fn (Document $d) => is_int(self::amountOf($d)))->values();
    }

    /** A receipt's amount as read (hundredths of its currency), or null when none was read. */
    public static function amountOf(Document $receipt): ?int
    {
        $amount = $receipt->extraction['receipt']['amount_minor'] ?? null;

        return is_numeric($amount) ? (int) $amount : null;
    }

    /** The date on a receipt, or null when there's none (or it can't be read). */
    public static function receiptDate(Document $receipt): ?CarbonImmutable
    {
        $on = $receipt->extraction['receipt']['date'] ?? null;
        if (! $on) {
            return null;
        }
        try {
            return CarbonImmutable::parse($on)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a receipt goes with a payment of these amounts made on this day: the same amount, and the date on it
     * within DATE_DAYS days (one with a date that can't be read never matches); with no date on it, received within
     * RECEIVED_DAYS days. Never one that arrived more than RECEIVED_DAYS days before the payment.
     *
     * @param  list<int>  $amounts  amountsOf()'s
     */
    public static function matches(Document $receipt, array $amounts, CarbonImmutable $date): bool
    {
        $r = $receipt->extraction['receipt'] ?? null;
        if (! $r || ! in_array((int) ($r['amount_minor'] ?? 0), $amounts, true)) {
            return false;
        }
        $received = $receipt->received_at;
        if ($received !== null && $received->format('Y-m-d H:i:s') < $date->subDays(self::RECEIVED_DAYS)->format('Y-m-d 00:00:00')) {
            return false;
        }
        if ($r['date'] ?? null) {
            $on = self::receiptDate($receipt);

            return $on !== null && abs($on->diffInDays($date)) <= self::DATE_DAYS;
        }

        return ! $received || abs(CarbonImmutable::parse($received->format('Y-m-d'))->diffInDays($date)) <= self::RECEIVED_DAYS;
    }

    /**
     * Receipts by amount, for matching many payments at once.
     *
     * @param  Collection<int, Document>  $receipts  receipts()'s
     * @return array<int, list<Document>> by amount
     */
    public static function byAmount(Collection $receipts): array
    {
        $out = [];
        foreach ($receipts as $d) {
            $out[self::amountOf($d)][] = $d;
        }

        return $out;
    }

    /**
     * The receipts among these that go with a payment (the same as forPayment() finds, without a query).
     *
     * @param  array<int, list<Document>>  $byAmount  byAmount()'s
     * @return list<Document>
     */
    public static function among(array $byAmount, BankTransaction|ManualExpense $item): array
    {
        $date = self::dateOf($item);
        $amounts = self::amountsOf($item);
        $out = [];
        foreach ($amounts as $amount) {
            foreach ($byAmount[$amount] ?? [] as $d) {
                if (self::matches($d, $amounts, $date)) {
                    $out[$d->id] = $d;
                }
            }
        }

        return array_values($out);
    }

    /** The page a receipt opens: its email, when it came as an attachment. */
    public static function href(Document $receipt): string
    {
        return '/documents/'.($receipt->parent_id ?? $receipt->id);
    }
}
