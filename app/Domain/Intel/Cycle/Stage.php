<?php

namespace App\Domain\Intel\Cycle;

/**
 * The stages of the cycle (docs/intelligence-cycle.md section 1), in the order a run goes through them: acquire
 * evidence, relate it, verify it, decide (M9), detect discrepancies, recommend actions, learn from outcomes (M10), and
 * preserve the run (its seal).
 */
enum Stage: string
{
    case Acquire = 'acquire';
    case Relate = 'relate';
    case Verify = 'verify';
    case Decide = 'decide';
    case Detect = 'detect';
    case Recommend = 'recommend';
    case Learn = 'learn';
    case Preserve = 'preserve';
}
