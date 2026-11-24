<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Models\CycleRun;

/**
 * preserve.seal (docs/intelligence-cycle.md 4.6 and 6.4): the end of every run. One cycle.completed audit event,
 * actor system, carrying the run's id, its counts and the digest of the findings open now, written inside the
 * transaction that ends the run (CycleRunner::finish()), so it anchors the run in the audit chain. A shadow run keeps
 * the digest with the run and writes no audit event.
 */
final class PreserveSeal implements CycleStep
{
    public const KEY = 'preserve.seal';

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Preserve;
    }

    public function writes(): bool
    {
        return false;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $run = CycleRun::query()->find($ctx->runId);
        if ($run) {
            app(CycleRunner::class)->finish($run);
        }

        return StepOutcome::ok();
    }
}
