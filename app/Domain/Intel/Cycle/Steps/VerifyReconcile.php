<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Ledger\Reconciler;
use App\Models\Account;

/**
 * verify.reconcile (docs/intelligence-cycle.md 4.3): checks each account against the bank's balances (Reconciler),
 * only looking: what it finds (gaps, accounts with nothing to check against, accounts not imported lately) is kept in
 * the run log, and the to-do list's own checks turn it into findings. One account at a time, carrying on from the
 * last one done when its time is up.
 */
final class VerifyReconcile implements CycleStep
{
    public const KEY = 'verify.reconcile';

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Verify;
    }

    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $c = (array) ($ctx->cursor['counts'] ?? []) + ['accounts' => 0, 'with_gaps' => 0, 'gaps' => 0, 'unchecked' => 0, 'stale' => 0];
        $after = (int) ($ctx->cursor['last_id'] ?? 0);
        foreach (Account::query()->where('id', '>', $after)->orderBy('id')->cursor() as $account) {
            $r = Reconciler::account($account);
            $c['accounts']++;
            $c['gaps'] += count($r['gaps']);
            $c['with_gaps'] += $r['gaps'] ? 1 : 0;
            $c['unchecked'] += $r['method'] === 'none' ? 1 : 0;
            $c['stale'] += $r['stale'] ? 1 : 0;
            $after = (int) $account->id;
            if (! $ctx->hasTime()) {
                return StepOutcome::more(['last_id' => $after, 'counts' => $c], $c);
            }
        }

        return StepOutcome::ok($c);
    }
}
