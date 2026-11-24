<?php

namespace App\Domain\Intel\Contracts;

use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;

/**
 * Something that finds problems (docs/intelligence-cycle.md section 4). Deterministic and read-only: detect() never
 * writes (tested with DB::listen); findings are recorded from its drafts by FindingsRegistry::sync().
 */
interface Detector
{
    /** The kind of finding it makes, such as 'tax.hmrc_unallocated'. */
    public function code(): string;

    /** Raised whenever its logic changes, so findings say which version found them. */
    public function version(): int;

    /** @return iterable<FindingDraft> */
    public function detect(CycleContext $ctx): iterable;

    /** How much the last detect() looked at: complete, capped or failed. */
    public function coverage(): Coverage;
}
