<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Intel\FindingDraft;

/**
 * detect.<code> (docs/intelligence-cycle.md 4.4): one detector of the registry (detect.todo is the to-do list's own
 * groups), run to see what it finds and how much it looked at. Detectors only read, so it runs in a shadow run too. The
 * run log keeps how many it found of each kind, the money by direction and currency (never added across them), its
 * coverage and its version. Recording the findings is detect.sync's work.
 */
final class RunDetector implements CycleStep
{
    public function __construct(private Detector $detector) {}

    public static function keyFor(Detector $detector): string
    {
        return mb_substr('detect.'.$detector->code(), 0, 40);
    }

    public function key(): string
    {
        return self::keyFor($this->detector);
    }

    public function stage(): Stage
    {
        return Stage::Detect;
    }

    public function writes(): bool
    {
        return false;
    }

    public function detector(): Detector
    {
        return $this->detector;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $found = 0;
        $byCode = [];
        $money = [];
        foreach ($this->detector->detect($ctx) as $draft) {
            /** @var FindingDraft $draft */
            $found++;
            $byCode[$draft->code] = ($byCode[$draft->code] ?? 0) + 1;
            if ($draft->amountPence !== null && $draft->direction !== null && $draft->direction !== 'none') {
                $key = $draft->direction.':'.($draft->currency ?? 'GBP');
                $money[$key] = ($money[$key] ?? 0) + abs($draft->amountPence);
            }
        }
        $coverage = $this->detector->coverage();
        $counts = ['found' => $found, 'by_code' => $byCode, 'money' => $money];
        if ($this->detector instanceof CoversCodes) {
            $short = [];
            foreach ($this->detector->codes() as $code) {
                if (($c = $this->detector->coverageOf($code)) !== Coverage::Complete) {
                    $short[$code] = $c->value;
                }
            }
            $counts['not_complete'] = $short;
        }

        return StepOutcome::ok($counts, $coverage, $this->detector->version());
    }
}
