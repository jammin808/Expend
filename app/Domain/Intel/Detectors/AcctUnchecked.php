<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Ref;
use App\Domain\Ledger\Reconciler;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\StatementBalance;
use Illuminate\Support\Facades\DB;

/**
 * Accounts that can't be checked against the bank (acct.unchecked, M4): an active account with lines whose imports
 * have no running balance and that has fewer than two statement balances to check between (Reconciler's method
 * 'none'), so a missing statement or a line in twice would go unnoticed. Accounts are picked out by two grouped
 * queries, and Reconciler::account() has the last word on each, so this never disagrees with it. No grade: it's
 * about what Expend can check, not about your money. Only reads.
 */
final class AcctUnchecked implements Detector
{
    public const CODE = 'acct.unchecked';

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
        $accounts = Account::query()->where('is_active', true)->orderBy('name')->get();
        // Lines, and lines with a balance (in the account's own currency for a pocket in another): enough of them
        // means Reconciler checks the running balance.
        $lines = BankTransaction::query()->whereIn('account_id', $accounts->pluck('id')->all() ?: [0])->groupBy('account_id')
            ->select('account_id', DB::raw('COUNT(*) AS n'), DB::raw('COUNT(balance_after_pence) AS b'), DB::raw('COUNT(balance_after_minor) AS bm'))->get()->keyBy('account_id');
        $balances = StatementBalance::query()->whereIn('account_id', $accounts->pluck('id')->all() ?: [0])->groupBy('account_id')
            ->select('account_id', DB::raw('COUNT(DISTINCT as_of) AS n'))->pluck('n', 'account_id');
        $drafts = [];
        $looked = 0;
        foreach ($accounts as $acc) {
            $l = $lines[$acc->id] ?? null;
            if ($l === null || (int) $l->n === 0) {
                continue; // nothing imported: nothing to check yet
            }
            $looked++;
            $withBalance = (int) ($acc->isForeign() ? $l->bm : $l->b);
            if ($withBalance >= max(2, (int) ceil((int) $l->n * 0.9)) || (int) ($balances[$acc->id] ?? 0) >= 2) {
                continue;
            }
            $r = Reconciler::account($acc);
            if ($r['method'] !== 'none') {
                continue;
            }
            $drafts[] = new FindingDraft(
                key: 'unchecked:'.$acc->id, code: self::CODE, group: 'reconcile', priority: 3,
                title: $acc->name.' can’t be checked against the bank',
                detail: $r['text'].' Until then a missing statement or a payment in twice wouldn’t be noticed. The balances are on the statements themselves.',
                href: '/accounts', subjectRef: Ref::make('acct', $acc->id), scopeRef: Ref::make('acct', $acc->id), direction: 'none', detectorVersion: self::VERSION,
            );
        }
        $this->checked = $looked;
        $this->coverage = Coverage::Complete;

        return $drafts;
    }
}
