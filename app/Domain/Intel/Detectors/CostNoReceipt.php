<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Intel\Ref;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Support\Money;

/**
 * Costs with no receipt (cost.no_receipt, M4): a business cost you confirmed, over the receipt threshold (the setting
 * intel_receipt_threshold_pence, £100 unless changed), that no receipt goes with (Intel\ReceiptMatch, the rule the
 * transaction page uses; a supplier's invoice read like a receipt counts as one), in a year that isn't locked. HMRC can ask for the receipt for any cost you claim. Left out:
 * costs a booking email already evidences (a stay or journey matched to the payment), and costs that never have a
 * receipt (bank charges and interest, mileage, use of home, depreciation, bad debts). Only reads.
 */
final class CostNoReceipt implements Detector
{
    public const CODE = 'cost.no_receipt';

    public const VERSION = 1;

    /** The setting the threshold is kept in, and its default (£100). */
    public const THRESHOLD_SETTING = 'intel_receipt_threshold_pence';

    public const THRESHOLD_DEFAULT = 10000;

    /** Costs that never come with a receipt. */
    public const NO_RECEIPT = ['finance', 'interest', 'bad_debts', 'depreciation', 'travel_mileage', 'use_of_home'];

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

    public static function threshold(): int
    {
        $v = Setting::get(self::THRESHOLD_SETTING, self::THRESHOLD_DEFAULT);

        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::THRESHOLD_DEFAULT;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $threshold = self::threshold();
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $skip = Category::query()->whereIn('code', self::NO_RECEIPT)->pluck('id')->all();
        $costs = Allocation::query()->where('kind', 'business_expense')->whereNotNull('confirmed_at')
            ->when($locked !== [], fn ($q) => $q->whereNotIn('tax_year', $locked))
            ->when($skip !== [], fn ($q) => $q->where(fn ($q) => $q->whereNull('category_id')->orWhereNotIn('category_id', $skip)))
            ->get(['id', 'bank_transaction_id', 'manual_expense_id', 'tax_year', 'category_id']);
        $txIds = $costs->pluck('bank_transaction_id')->filter()->unique()->values();
        $expIds = $costs->pluck('manual_expense_id')->filter()->unique()->values();
        $payments = collect();
        foreach ($txIds->chunk(500) as $chunk) {
            $payments = $payments->concat(BankTransaction::query()->whereIn('id', $chunk->all())->where('amount_pence', '<', -$threshold)
                ->get(['id', 'posted_on', 'transaction_on', 'amount_pence', 'original_amount_minor', 'original_currency', 'merchant_amount_minor', 'merchant_currency',
                    'description', 'counterparty', 'tax_year']));
        }
        foreach ($expIds->chunk(500) as $chunk) {
            $payments = $payments->concat(ManualExpense::query()->whereIn('id', $chunk->all())->where('amount_pence', '<', -$threshold)
                ->get(['id', 'spent_on', 'amount_pence', 'original_amount_minor', 'original_currency', 'description', 'tax_year']));
        }
        // A stay or journey matched to the payment: its booking email is the evidence.
        $booked = TravelItem::query()->whereIn('bank_transaction_id', $payments->filter(fn ($p) => $p instanceof BankTransaction)->pluck('id')->all() ?: [0])
            ->pluck('bank_transaction_id')->flip();
        $receipts = ReceiptMatch::byAmount(ReceiptMatch::receipts());
        // A supplier's invoice read like a receipt (its amount and date) is as good as one.
        $invoices = ReceiptMatch::byAmount(ReceiptMatch::receipts('invoice'));
        $drafts = [];
        foreach ($payments as $p) {
            $tx = $p instanceof BankTransaction;
            if (($tx && isset($booked[$p->id])) || ReceiptMatch::among($receipts, $p) !== [] || ReceiptMatch::among($invoices, $p) !== []) {
                continue;
            }
            $name = $tx ? ($p->counterparty ?: $p->description) : $p->description;
            $on = ReceiptMatch::dateOf($p);
            $drafts[] = new FindingDraft(
                key: 'noreceipt:'.($tx ? $p->id : 'exp:'.$p->id), code: self::CODE, group: 'records', priority: 3,
                title: 'No receipt for '.$name.': '.Money::format(abs((int) $p->amount_pence), 'GBP', false).' on '.$on->format('j M Y'),
                detail: 'A business cost over '.Money::format($threshold, 'GBP', false).' with no receipt found for it. HMRC can ask to see it: forward or upload the receipt '
                    .'(or the invoice), and it’s matched by its amount and date. If there isn’t one to keep, mark this not relevant.',
                href: '/transactions?year='.$p->tax_year.'&q='.urlencode($name),
                subjectRef: $tx ? Ref::make('tx', $p->id) : Ref::make('exp', $p->id, null, sha1($p->spent_on?->format('Y-m-d').'|'.$p->amount_pence.'|'.$p->description)),
                scopeRef: Ref::make('year', $p->tax_year), amountPence: (int) $p->amount_pence, direction: 'none', grade: Grades::forCode(self::CODE)[0],
                detectorVersion: self::VERSION,
            );
        }
        $this->checked = $payments->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }
}
