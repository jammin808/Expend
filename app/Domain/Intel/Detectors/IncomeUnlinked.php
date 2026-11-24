<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Models\Allocation;
use App\Models\Category;
use App\Models\TaxYear;
use App\Support\Money;

/**
 * Income with no invoice or job (income.unlinked, M4): money in you confirmed as business income (turnover, not
 * other business income such as interest) that isn't linked to an invoice or a job, in a year that isn't locked. A
 * job's profit, and what an invoice has had paid, count only income linked to them, so an unpaid-invoice reminder
 * could go to a client who's paid. Linking it is done in Transactions. One finding per payment. Money paid back to a
 * client (a refund recorded as negative turnover) isn't income, and isn't listed. Only reads.
 */
final class IncomeUnlinked implements Detector
{
    public const CODE = 'income.unlinked';

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
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $other = Category::query()->where('code', 'other_income')->value('id');
        $rows = Allocation::query()->where('kind', 'business_income')->whereNotNull('confirmed_at')
            ->when($locked !== [], fn ($q) => $q->whereNotIn('tax_year', $locked))
            ->with(['transaction:id,account_id,posted_on,transaction_on,amount_pence,description,counterparty,tax_year', 'manualExpense:id,spent_on,description,amount_pence,tax_year'])
            ->orderBy('id')->get(['id', 'bank_transaction_id', 'manual_expense_id', 'tax_year', 'category_id', 'amount_pence', 'invoice_id', 'work_job_id']);
        // A payment is linked if any of its lines is (a split: part for an invoice, part for a job).
        $by = $rows->groupBy(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id);
        $drafts = [];
        foreach ($by as $source => $lines) {
            if ($lines->contains(fn (Allocation $a) => $a->invoice_id !== null || $a->work_job_id !== null)) {
                continue;
            }
            $turnover = $lines->reject(fn (Allocation $a) => $other !== null && (int) $a->category_id === (int) $other);
            if ($turnover->isEmpty()) {
                continue;
            }
            $first = $turnover->first();
            $tx = $first->transaction;
            $exp = $first->manualExpense;
            if ($tx === null && $exp === null) {
                continue; // a line from a held or undone statement
            }
            $amount = (int) $turnover->sum('amount_pence');
            if ($amount <= 0) {
                continue; // money paid back to a client (a refund recorded against turnover), not income
            }
            $name = $tx ? ($tx->counterparty ?: $tx->description) : $exp->description;
            $on = $tx ? $tx->effectiveDate()->format('j M Y') : $exp->spent_on->format('j M Y');
            $drafts[] = new FindingDraft(
                key: 'unlinked:'.($tx ? $tx->id : 'exp:'.$exp->id), code: self::CODE, group: 'records', priority: 3,
                title: 'Income with no invoice or job: '.Money::format($amount, 'GBP', false).' from '.$name.' on '.$on,
                detail: 'Recorded as business income, but not linked to an invoice or a job, so the job’s profit and what its invoice has been paid leave it out '
                    .'(an unpaid-invoice reminder could go to a client who’s paid). Link it in Transactions. If it isn’t for any job, mark this not relevant.',
                href: '/transactions?year='.$first->tax_year.'&q='.urlencode($name),
                subjectRef: $tx ? Ref::make('tx', $tx->id) : Ref::make('exp', $exp->id, null, sha1($exp->spent_on?->format('Y-m-d').'|'.$exp->amount_pence.'|'.$exp->description)),
                scopeRef: Ref::make('year', $first->tax_year), amountPence: $amount, direction: 'none', grade: Grades::forCode(self::CODE)[0], detectorVersion: self::VERSION,
            );
        }
        $this->checked = $by->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }
}
