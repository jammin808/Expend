<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Matching\TransferPairer;

/**
 * relate.transfers (docs/intelligence-cycle.md 4.2): finds money moving between your own accounts, so it's never
 * counted as spending or income. The same work as ledger:transfers (work(), which the command also calls): pair what
 * matches, suggest one-sided transfers from your names and accounts, take back earlier suggestions that no longer
 * hold, and leave ties for you.
 */
final class RelateTransfers implements CycleStep
{
    public const KEY = 'relate.transfers';

    public function __construct(private TransferPairer $pairer) {}

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
        return StepOutcome::ok(self::work($this->pairer));
    }

    /** @return array{paired:int, one_sided:int, cleared:int, ambiguous:int} */
    public static function work(TransferPairer $pairer): array
    {
        $pairs = $pairer->pairAll();
        $one = $pairer->suggestOneSided();

        return ['paired' => $pairs, 'one_sided' => $one, 'cleared' => $pairer->cleared, 'ambiguous' => $pairer->ambiguous];
    }
}
