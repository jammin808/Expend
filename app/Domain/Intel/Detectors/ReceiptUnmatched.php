<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Intel\Ref;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Receipts with no payment (receipt.unmatched, M4): a receipt read from an email or an upload that no bank line or
 * cash expense goes with (Intel\ReceiptMatch, the rule the transaction page uses). Usually it was paid with a card
 * whose statement isn't imported, or in cash and never added. Only receipts dated within the span of your statements
 * (between the first and last line imported) and in a year that isn't locked: one from before your records begin
 * can't be matched. Only reads.
 */
final class ReceiptUnmatched implements Detector
{
    public const CODE = 'receipt.unmatched';

    public const VERSION = 1;

    private ?int $checked = null;

    private Coverage $coverage = Coverage::Failed;

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }

    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $first = BankTransaction::query()->min('posted_on');
        $last = BankTransaction::query()->max('posted_on');
        $locked = array_flip(TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all());
        $drafts = [];
        $checked = 0;
        if ($first !== null) {
            $from = CarbonImmutable::parse(substr((string) $first, 0, 10));
            $to = CarbonImmutable::parse(substr((string) $last, 0, 10));
            $receipts = ReceiptMatch::receipts()->filter(function (Document $d) use ($from, $to, $locked) {
                $on = self::when($d);

                return $on !== null && $on->betweenIncluded($from, $to) && ! isset($locked[UkTaxYear::fromDate($on)->label()]);
            })
                // An email read as a receipt and its attachment read as the same receipt are one receipt.
                ->unique(fn (Document $d) => ($d->parent_id ?? $d->id).':'.ReceiptMatch::amountOf($d))->values();
            $checked = $receipts->count();
            $payments = self::paymentsNear($receipts);
            foreach ($receipts as $d) {
                if (self::paidBy($d, $payments)) {
                    continue;
                }
                $r = $d->extraction['receipt'];
                $currency = strtoupper((string) ($r['currency'] ?? 'GBP')) ?: 'GBP';
                $amount = (int) $r['amount_minor'];
                $on = self::when($d);
                $merchant = $r['merchant'] ?? $d->email_subject ?? $d->original_name;
                $drafts[] = new FindingDraft(
                    key: 'receipt:'.$d->id, code: self::CODE, group: 'missing', priority: 3,
                    title: 'Receipt with no payment: '.Money::format($amount, $currency, false).' at '.$merchant.' on '.$on->format('j M Y'),
                    detail: 'No bank line or cash expense of that amount within a few days of it. If it was paid with a card whose statement isn’t imported, import that statement; '
                        .'if you paid in cash, add it as a cash expense. If it wasn’t a business cost, mark this not relevant.',
                    href: ReceiptMatch::href($d), subjectRef: Ref::make('doc', $d->id, 'f=receipt', $d->sha256),
                    scopeRef: Ref::make('year', UkTaxYear::fromDate($on)->label()), amountPence: -$amount, currency: $currency,
                    direction: 'unrecorded', grade: Grades::forCode(self::CODE)[0], detectorVersion: self::VERSION,
                );
            }
        }
        $this->checked = $checked;
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /** The day a receipt is about: the date on it, or when it arrived. */
    public static function when(Document $d): ?CarbonImmutable
    {
        return ReceiptMatch::receiptDate($d) ?? ($d->received_at ? CarbonImmutable::parse($d->received_at->format('Y-m-d')) : null);
    }

    /**
     * The bank lines and cash expenses that could go with any of these receipts, by each amount they could be for
     * (ReceiptMatch::amountsOf()): of those amounts, or in another currency, around the receipts' dates. Three queries.
     *
     * @param  Collection<int, Document>  $receipts
     * @return array<int, list<BankTransaction|ManualExpense>>
     */
    public static function paymentsNear(Collection $receipts): array
    {
        if ($receipts->isEmpty()) {
            return [];
        }
        $amounts = $receipts->map(fn (Document $d) => ReceiptMatch::amountOf($d))->unique()->values();
        $dates = $receipts->map(fn (Document $d) => self::when($d))->filter()->sort()->values();
        $from = $dates->first()->subDays(ReceiptMatch::RECEIVED_DAYS + ReceiptMatch::DATE_DAYS)->format('Y-m-d');
        $to = $dates->last()->addDays(ReceiptMatch::RECEIVED_DAYS + ReceiptMatch::DATE_DAYS)->format('Y-m-d');
        $signed = $amounts->flatMap(fn ($a) => [$a, -$a])->all();
        $out = [];
        $add = function ($payment) use (&$out) {
            foreach (ReceiptMatch::amountsOf($payment) as $a) {
                $out[$a][] = $payment;
            }
        };
        foreach (array_chunk($signed, 500) as $chunk) {
            BankTransaction::query()->whereIn('amount_pence', $chunk)->whereDate('posted_on', '>=', $from)->whereDate('posted_on', '<=', $to)
                ->get(['id', 'posted_on', 'transaction_on', 'amount_pence', 'original_amount_minor', 'original_currency', 'merchant_amount_minor', 'merchant_currency'])->each($add);
            ManualExpense::query()->whereIn('amount_pence', $chunk)->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
                ->get(['id', 'spent_on', 'amount_pence', 'original_amount_minor', 'original_currency'])->each($add);
        }
        // Paid in another currency: its own amount isn't in pounds, so it's looked at whatever the pounds were.
        BankTransaction::query()->whereNotIn('amount_pence', $signed ?: [0])->where(fn ($q) => $q->whereNotNull('original_amount_minor')->orWhereNotNull('merchant_amount_minor'))
            ->whereDate('posted_on', '>=', $from)->whereDate('posted_on', '<=', $to)
            ->get(['id', 'posted_on', 'transaction_on', 'amount_pence', 'original_amount_minor', 'original_currency', 'merchant_amount_minor', 'merchant_currency'])->each($add);
        ManualExpense::query()->whereNotIn('amount_pence', $signed ?: [0])->whereNotNull('original_amount_minor')->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
            ->get(['id', 'spent_on', 'amount_pence', 'original_amount_minor', 'original_currency'])->each($add);

        return $out;
    }

    /**
     * Whether some payment goes with this receipt, by ReceiptMatch's rule.
     *
     * @param  array<int, list<BankTransaction|ManualExpense>>  $payments  paymentsNear()'s
     */
    public static function paidBy(Document $receipt, array $payments): bool
    {
        foreach ($payments[ReceiptMatch::amountOf($receipt)] ?? [] as $p) {
            if (ReceiptMatch::matches($receipt, ReceiptMatch::amountsOf($p), ReceiptMatch::dateOf($p))) {
                return true;
            }
        }

        return false;
    }
}
