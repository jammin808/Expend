<?php

namespace App\Domain\Intel\Cycle;

/**
 * One step of a cycle run (docs/intelligence-cycle.md section 4: the plan puts this contract beside Detector; it lives
 * here with the runner that uses it). A step is short: it works until $ctx->hasTime() says stop, then returns 'more'
 * with a cursor, and the next tick carries on from it. It must be safe to run twice.
 */
interface CycleStep
{
    /** Its key in the run log, such as 'relate.transfers' (at most 40 characters). */
    public function key(): string;

    public function stage(): Stage;

    /**
     * Whether it changes anything outside the run log. A shadow run skips every step that does; one that doesn't
     * runs in full and says what it would have changed (in shadow, $ctx->shadow).
     */
    public function writes(): bool;

    /** Do the work, or the next part of it from $ctx->cursor. */
    public function run(CycleContext $ctx): StepOutcome;
}
