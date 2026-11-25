<?php

namespace App\Domain\Intel;

use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
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
 *
 * A receipt photo from the phone (Quick\QuickEntries) belongs to its entry, whatever state the entry is in (owner()):
 * it goes only with the expense it was added as, or with the bank line it's attached to, and with nothing else, so the
 * photo of a cash expense is never another payment's receipt and one you took back matches nothing. Its figures are
 * what you typed (figures()), whose currency is certain: €15.00 goes with a line that was €15.00, never with £15.00.
 * Receipts read from email keep the comparison they had, since their currency is taken as pounds when none was read.
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

        // Your own receipt photo for it first (the line it's attached to, the expense it was added as).
        $own = self::ownedBy($item);
        $read = Document::query()->where('kind', 'receipt')->whereNotNull('extraction')
            ->whereIn('extraction->receipt->amount_minor', array_merge($amounts, array_map('strval', $amounts)))
            ->where(fn ($q) => $q->whereNull('received_at')->orWhere('received_at', '>=', $date->subDays(self::RECEIVED_DAYS)->format('Y-m-d 00:00:00')))
            ->whereNotIn('id', QuickEntry::query()->whereNotNull('document_id')->select('document_id'))
            ->orderBy('received_at')->orderBy('id')->limit(self::PAGE_LIMIT)->get(self::COLUMNS)
            ->each(fn (Document $d) => $d->setRelation('quickOwner', null))
            ->filter(fn (Document $d) => self::matches($d, $amounts, $date))->values();

        return $own->concat($read)->unique('id')->values();
    }

    /**
     * The receipt photos from the phone that belong to this payment: the line they're attached to, or the expense
     * they were added as.
     *
     * @return Collection<int, Document>
     */
    public static function ownedBy(BankTransaction|ManualExpense $item): Collection
    {
        $entries = QuickEntry::query()->whereNotNull('document_id')
            ->when($item instanceof BankTransaction, fn ($q) => $q->where('bank_transaction_id', $item->getKey())->where('status', 'attached'),
                fn ($q) => $q->where('manual_expense_id', $item->getKey())->where('status', 'added'))
            ->orderBy('id')->get();
        if ($entries->isEmpty()) {
            return collect();
        }
        $docs = Document::query()->whereIn('id', $entries->pluck('document_id')->all())->get(self::COLUMNS)->keyBy('id');

        return $entries->map(function (QuickEntry $e) use ($docs) {
            $d = $docs->get($e->document_id);
            $d?->setRelation('quickOwner', $e);

            return $d;
        })->filter()->values();
    }

    /**
     * The phone entry a document belongs to (in any state: added, waiting, attached, taken back), or null for one
     * that isn't a photo from the phone. A document taken as a duplicate's photo belongs to the duplicate.
     */
    public static function owner(Document $document): ?QuickEntry
    {
        if ($document->relationLoaded('quickOwner')) {
            return $document->getRelation('quickOwner');
        }
        $entries = QuickEntry::query()->where('document_id', $document->id)->orderBy('id')->get();
        $owner = $entries->first(fn (QuickEntry $e) => $e->isLive()) ?? $entries->first();
        $document->setRelation('quickOwner', $owner);

        return $owner;
    }

    /**
     * A receipt's figures: for a photo from the phone, what you typed (its currency certain); otherwise what was read
     * from it, its currency as read (pounds when none was).
     *
     * @return array{hundredths:?int, currency:string, date:?CarbonImmutable, source:string, certain:bool}
     */
    public static function figures(Document $receipt): array
    {
        $owner = self::owner($receipt);
        if ($owner !== null && $owner->amount_minor !== null) {
            $f = $owner->figures();

            return ['hundredths' => $f['hundredths'], 'currency' => $f['currency'], 'date' => $f['date'], 'source' => 'quick', 'certain' => true];
        }
        $r = $receipt->extraction['receipt'] ?? null;
        $amount = $r['amount_minor'] ?? null;
        $on = null;
        if ($r['date'] ?? null) {
            try {
                $on = CarbonImmutable::parse($r['date'])->startOfDay();
            } catch (\Throwable) {
                $on = null;
            }
        }

        return ['hundredths' => is_numeric($amount) ? (int) $amount : null, 'currency' => strtoupper((string) ($r['currency'] ?? 'GBP')) ?: 'GBP',
            'date' => $on ?? ($owner?->spent_on ? CarbonImmutable::parse($owner->spent_on->format('Y-m-d')) : null), 'source' => $owner ? 'quick' : 'read', 'certain' => false];
    }

    /**
     * Whether a payment shows this amount in this currency: its sterling for pounds, the amount in the account's own
     * currency, or the amount the shop charged, each only when it's in that currency. For a receipt whose currency is
     * certain (a photo from the phone). $hundredths in hundredths of the currency.
     */
    public static function sameAmountIn(BankTransaction $line, int $hundredths, string $currency): bool
    {
        $currency = strtoupper($currency);
        if ($currency === 'GBP' && abs((int) $line->amount_pence) === $hundredths) {
            return true;
        }
        if ($line->original_amount_minor && strtoupper((string) $line->original_currency) === $currency
            && Money::toHundredths(abs((int) $line->original_amount_minor), $currency) === $hundredths) {
            return true;
        }

        return $line->merchant_amount_minor && strtoupper((string) $line->merchant_currency) === $currency
            && Money::toHundredths(abs((int) $line->merchant_amount_minor), $currency) === $hundredths;
    }

    /**
     * Every receipt with an amount read from it, for matching many payments at once (the detectors): the same
     * receipts forPayment() looks among. With $kind 'invoice': a supplier's invoice whose amount and date were read
     * the way a receipt's are (cost.no_receipt takes it as the receipt; the transaction page lists receipts only).
     *
     * @return Collection<int, Document>
     */
    public static function receipts(string $kind = 'receipt', bool $withQuick = false): Collection
    {
        $owners = QuickEntry::query()->whereNotNull('document_id')->orderBy('id')->get()->groupBy('document_id');
        $read = Document::query()->where('kind', $kind)->whereNotNull('extraction')->orderBy('received_at')->orderBy('id')->get(self::COLUMNS)
            ->reject(fn (Document $d) => $owners->has($d->id))->each(fn (Document $d) => $d->setRelation('quickOwner', null));
        if (! $withQuick || $kind !== 'receipt' || $owners->isEmpty()) {
            return $read->filter(fn (Document $d) => is_int(self::amountOf($d)))->values();
        }
        // Your receipt photos too (read or not: the amount is what you typed), each with the entry it belongs to.
        $quick = Document::query()->whereIn('id', $owners->keys()->all())->orderBy('received_at')->orderBy('id')->get(self::COLUMNS)
            ->each(function (Document $d) use ($owners) {
                $entries = $owners->get($d->id);
                $d->setRelation('quickOwner', $entries->first(fn (QuickEntry $e) => $e->isLive()) ?? $entries->first());
            });

        return $read->concat($quick)->filter(fn (Document $d) => is_int(self::amountOf($d)))->values();
    }

    /** A receipt's amount (hundredths of its currency): what you typed for a photo from the phone, otherwise as read; null when none. */
    public static function amountOf(Document $receipt): ?int
    {
        return self::figures($receipt)['hundredths'];
    }

    /** The date on a receipt (the day you gave for a photo from the phone), or null when there's none (or it can't be read). */
    public static function receiptDate(Document $receipt): ?CarbonImmutable
    {
        return self::figures($receipt)['date'];
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
        if (self::owner($receipt) !== null) {
            return false; // a photo from the phone goes only with its own expense or line (among(), forPayment())
        }
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
                if (($owner = self::owner($d)) !== null ? self::ownedByItem($owner, $item) : self::matches($d, $amounts, $date)) {
                    $out[$d->id] = $d;
                }
            }
        }

        return array_values($out);
    }

    /** Whether a phone entry's photo belongs to this payment: the line it's attached to, or the expense it was added as. */
    public static function ownedByItem(QuickEntry $owner, BankTransaction|ManualExpense $item): bool
    {
        return $item instanceof BankTransaction
            ? $owner->status === 'attached' && (int) $owner->bank_transaction_id === (int) $item->getKey()
            : $owner->status === 'added' && (int) $owner->manual_expense_id === (int) $item->getKey();
    }

    /** The page a receipt opens: its email, when it came as an attachment. */
    public static function href(Document $receipt): string
    {
        return '/documents/'.($receipt->parent_id ?? $receipt->id);
    }
}
