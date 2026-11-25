<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\ReceiptAttacher;

/**
 * relate.receipts: business-card receipts from your phone still waiting for their bank line go on it when exactly one
 * line fits and no other receipt fits that line (offered there for one tap, never confirmed by itself), and
 * suggestions from receipts no longer on their line are taken back. Open years only. The same work as
 * ledger:receipts with the cycle off. Runs after relate.transfers (a transfer is never a receipt's payment). Chunked by
 * entry id: it carries on from its cursor when its time is up.
 */
final class RelateReceipts implements CycleStep
{
    public const KEY = 'relate.receipts';

    public function __construct(private ReceiptAttacher $attacher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Relate;
    }

    public function writes(): bool
    {
        return true;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $after = (int) ($ctx->cursor['last_id'] ?? 0);
        $done = (int) ($ctx->cursor['attached'] ?? 0);
        $r = AuditContext::with('matcher', fn () => $this->attacher->sweep($after, fn () => $ctx->hasTime(5)));
        $attached = $done + $r['attached'];
        if ($r['last_id'] !== null) {
            return StepOutcome::more(['last_id' => $r['last_id'], 'attached' => $attached], ['attached' => $attached]);
        }

        return StepOutcome::ok(['attached' => $attached, 'taken_back' => $r['taken_back'], 'read_again' => QuickEntries::retryCappedReads()]);
    }
}
