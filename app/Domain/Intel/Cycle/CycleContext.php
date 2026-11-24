<?php

namespace App\Domain\Intel\Cycle;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What a step or detector runs within (docs/intelligence-cycle.md section 4): the run it belongs to (none for a
 * page view or a command run by hand), the time it must finish by, and whether it's a shadow run (record what would
 * change, change nothing). M3 needs only these; the cycle runner (M5) adds the AI allowance, the cursor and the
 * hand-off lists, each with a default so code written against this keeps working.
 */
class CycleContext
{
    public readonly ?CarbonImmutable $deadline;

    public function __construct(
        public readonly ?int $runId = null,
        ?CarbonInterface $deadline = null,
        public readonly bool $shadow = false,
    ) {
        $this->deadline = $deadline === null ? null : CarbonImmutable::instance($deadline);
    }

    /** Whether there's time left for more work (at least $seconds of it); always, with no deadline. */
    public function hasTime(int $seconds = 0): bool
    {
        return $this->deadline === null || CarbonImmutable::now()->addSeconds($seconds)->lt($this->deadline);
    }
}
