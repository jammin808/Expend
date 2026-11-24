<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Todo\FindingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * detect.sync (docs/intelligence-cycle.md 4.4): records what the detectors find, as ledger:findings does
 * (FindingsRegistry::sync, which is coverage-aware: a finding is resolved only when its detector looked at everything;
 * a capped one is unseen, a failed one stays open). Its events carry the run's id. In a shadow run nothing is kept:
 * the counts say what would have been opened, resolved or marked unseen.
 *
 * The findings are recorded one at a time (FindingsRegistry's lock, also taken by the to-do list's buttons, the
 * history page, the nudges and ledger:findings). While something else holds it, the step doesn't sit in the worker
 * waiting: it tries again a minute later (and FindingsRegistry::sync()'s "someone else is recording" answer, which
 * recorded nothing, is never taken as a sync that found nothing). After WAITS minutes it counts as failed.
 */
final class DetectSync implements CycleStep
{
    public const KEY = 'detect.sync';

    /** The lock FindingsRegistry records findings under. */
    public const LOCK = 'findings-sync';

    /** Minutes it waits for the findings to be free before it counts as failed. */
    public const WAITS = 10;

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Detect;
    }

    /** It records findings, but does it itself only in a real run: in shadow it works it all out and keeps nothing. */
    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        // A look, without waiting: free now, it's let go at once and FindingsRegistry takes it.
        $probe = Cache::lock(self::LOCK, 5);
        if (! $probe->get()) {
            return $this->busy($ctx);
        }
        $probe->release();

        $r = FindingsRegistry::sync($ctx);
        if (! empty($r['locked']) || ! array_key_exists('failed', $r)) {
            return $this->busy($ctx); // taken meanwhile: nothing was recorded
        }
        $coverage = ! empty($r['failed']) ? Coverage::Failed : (! empty($r['unseen']) || ! empty($r['kept']) ? Coverage::Capped : Coverage::Complete);

        return StepOutcome::ok(['new' => $r['new'], 'resolved' => $r['resolved'], 'open' => $r['open'], 'kept' => (int) ($r['kept'] ?? 0),
            'unseen' => (int) ($r['unseen'] ?? 0), 'failed' => array_values($r['failed'] ?? [])] + ($ctx->shadow ? ['shadow' => true] : []), $coverage);
    }

    /** Something else is recording the findings: again in a minute, or failed after WAITS tries. */
    private function busy(CycleContext $ctx): StepOutcome
    {
        $waits = (int) ($ctx->cursor['waits'] ?? 0) + 1;
        if ($waits >= self::WAITS) {
            return StepOutcome::failed('The findings were being recorded by something else for '.self::WAITS.' minutes, so they weren’t recorded in this run.');
        }

        return StepOutcome::waitUntil(CarbonImmutable::now()->addMinute(), ['waits' => $waits], ['waiting_for' => 'the findings to be free']);
    }
}
