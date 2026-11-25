<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Income\IncomeClient;
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
 * Income not assigned to a client, job or invoice (income.unlinked, M4; version 2 by income design v3): money in you
 * confirmed as business income (turnover, not other business income such as interest) that no client, job or invoice
 * is set for, in a year that isn't locked. A client's figures, a job's profit and what an invoice has had paid count
 * only income assigned to them, so an unpaid-invoice reminder could go to a client who's paid.
 *
 * Line by line: a line counts as assigned when it has a job or an invoice, or a client is worked out for it
 * (IncomeClient: its invoice's, its job's, or its own, merges resolved). Income for a client with no job is done once
 * the client is set (owner answer): nothing asks which job it was for. A payment is flagged for the sum of its
 * unassigned money-in lines, so a split's unassigned part is flagged for that part. Money paid back to a client (a
 * refund recorded as negative turnover) isn't income, and isn't listed. One finding per payment, keyed unlinked:{tx}
 * as in version 1, so a finding resolves once the client, job or invoice is set. Assigning it is done in Transactions.
 * Only reads.
 */
final class IncomeUnlinked implements Detector
{
    public const CODE = 'income.unlinked';

    public const VERSION = 2;

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
        // Each line with its worked-out client (one query: IncomeClient's joins), so client-only income counts as assigned.
        $rows = IncomeClient::scope(Allocation::query()->select(['allocations.id', 'allocations.bank_transaction_id', 'allocations.manual_expense_id', 'allocations.tax_year',
            'allocations.category_id', 'allocations.amount_pence', 'allocations.invoice_id', 'allocations.work_job_id', 'allocations.client_id']))
            ->where('allocations.kind', 'business_income')->whereNotNull('allocations.confirmed_at')
            ->when($locked !== [], fn ($q) => $q->whereNotIn('allocations.tax_year', $locked))
            ->with(['transaction:id,account_id,posted_on,transaction_on,amount_pence,description,counterparty,tax_year', 'manualExpense:id,spent_on,description,amount_pence,tax_year'])
            ->orderBy('allocations.id')->get();
        $by = $rows->groupBy(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id);
        $drafts = [];
        foreach ($by as $lines) {
            $turnover = $lines->reject(fn (Allocation $a) => $other !== null && (int) $a->category_id === (int) $other);
            // Money in on a line nothing is set for: no invoice, no job, and no client worked out for it.
            $loose = $turnover->filter(fn (Allocation $a) => (int) $a->amount_pence > 0 && $a->invoice_id === null && $a->work_job_id === null
                && $a->getAttribute(IncomeClient::COLUMN) === null);
            $amount = (int) $loose->sum('amount_pence');
            if ($amount <= 0) {
                continue; // all assigned, or money paid back to a client (a refund recorded against turnover), not income
            }
            $first = $loose->first();
            $tx = $first->transaction;
            $exp = $first->manualExpense;
            if ($tx === null && $exp === null) {
                continue; // a line from a held or undone statement
            }
            $all = (int) $turnover->filter(fn (Allocation $a) => (int) $a->amount_pence > 0)->sum('amount_pence');
            $part = $amount < $all;
            $name = $tx ? ($tx->counterparty ?: $tx->description) : $exp->description;
            $on = $tx ? $tx->effectiveDate()->format('j M Y') : $exp->spent_on->format('j M Y');
            $money = Money::format($amount, 'GBP', false);
            $drafts[] = new FindingDraft(
                key: 'unlinked:'.($tx ? $tx->id : 'exp:'.$exp->id), code: self::CODE, group: 'records', priority: 3,
                title: 'Income with no client, job or invoice: '.$money.($part ? ' of '.Money::format($all, 'GBP', false) : '').' from '.$name.' on '.$on,
                detail: ($part ? 'Part of this payment is assigned, but '.$money.' of it' : 'Recorded as business income, but it').' isn’t assigned to a client, job or invoice, '
                    .'so no client’s or job’s figures include it (and an unpaid-invoice reminder could go to a client who’s paid). '
                    .'Set the client, job or invoice in Transactions. If it isn’t from a client, mark this not relevant.',
                href: '/transactions?year='.$first->tax_year.'&unassigned=1&q='.urlencode($name),
                subjectRef: $tx ? Ref::make('tx', $tx->id) : Ref::make('exp', $exp->id, null, sha1($exp->spent_on?->format('Y-m-d').'|'.$exp->amount_pence.'|'.$exp->description)),
                scopeRef: Ref::make('year', $first->tax_year), amountPence: $amount, direction: 'none', grade: Grades::forCode(self::CODE)[0], detectorVersion: self::VERSION,
            );
        }
        $this->checked = $by->count();
        $this->coverage = Coverage::Complete;

        return $drafts;
    }
}
