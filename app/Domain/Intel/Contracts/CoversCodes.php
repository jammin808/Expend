<?php

namespace App\Domain\Intel\Contracts;

use App\Domain\Intel\Coverage;

/**
 * A detector that makes more than one kind of finding (the to-do list's groups: TodoGroupsDetector), and can say how
 * much it looked at for each: one kind's check going wrong leaves the others complete.
 */
interface CoversCodes
{
    /** @return list<string> the codes it makes */
    public function codes(): array;

    /** How much the last detect() looked at for this code. */
    public function coverageOf(string $code): Coverage;
}
