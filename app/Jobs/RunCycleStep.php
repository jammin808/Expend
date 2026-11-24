<?php

namespace App\Jobs;

use App\Domain\Intel\Cycle\CycleRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Runs a cycle run's next steps in the queue worker (docs/intelligence-cycle.md 6.1): queued by the tick (ledger:cycle
 * tick) or when a run is asked for (after an import, "Check now"). One per run at a time, and one step job at all at a
 * time (the lock intel:cycle): a job that finds another at work leaves it, and the next tick queues it again. It works
 * for up to 90 seconds at night and 30 by day, then leaves the rest to the next tick (CycleRunner::work()).
 */
class RunCycleStep implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Its steps stop themselves within 90 seconds; this is the worker's backstop. Kept short so the worker (started
     * every minute, --max-time=240) ends within about 400 seconds even when a step overruns, and a step stopped here is
     * noticed (and resumed, CycleRunner::housekeep()) soon after.
     */
    public int $timeout = 150;

    public int $uniqueFor = 600;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return 'cycle-run:'.$this->runId;
    }

    public function handle(CycleRunner $runner): void
    {
        $lock = Cache::lock(CycleRunner::LOCK, CycleRunner::LOCK_SECONDS);
        if (! $lock->get()) {
            return;
        }
        try {
            $runner->work($this->runId);
        } finally {
            $lock->release();
        }
    }
}
