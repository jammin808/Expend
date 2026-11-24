<?php

namespace App\Domain\Intel\Cycle;

use App\Domain\Intel\Ref;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What a step or detector runs within (docs/intelligence-cycle.md section 4): the run it belongs to (none for a
 * page view or a command run by hand), the time it must finish by, and whether it's a shadow run (record what would
 * change, change nothing). For a step of a cycle run (CycleRunner) it also carries:
 *  - the run's AI allowance ($ai: allow() before work done elsewhere, within() around calls made here);
 *  - the cursor the step saved when its time last ran out, to carry on from;
 *  - the run's kind and scope (an import run's batch and account);
 *  - the hand-offs between steps: refs acquired or changed in this run ($changed), proposed ($proposed) and acted on
 *    ($acted), kept with the run so a later step (in another job) sees them.
 *
 * Every argument after the first three has a default, so `new CycleContext` and
 * `new CycleContext(runId:, deadline:, shadow:)` keep working.
 */
class CycleContext
{
    /** At most this many refs are handed on in each list. */
    public const HANDOFF_MAX = 500;

    public readonly ?CarbonImmutable $deadline;

    public readonly AiAllowance $ai;

    /** @var list<string> */
    public array $changed;

    /** @var list<string> */
    public array $proposed;

    /** @var list<string> */
    public array $acted;

    /**
     * @param  array<string, mixed>  $cursor  where the step carries on from (empty: from the start)
     * @param  list<string>  $scope  refs the run is about (an import run: batch:91, acct:3); empty: everything
     * @param  list<string>  $changed
     * @param  list<string>  $proposed
     * @param  list<string>  $acted
     */
    public function __construct(
        public readonly ?int $runId = null,
        ?CarbonInterface $deadline = null,
        public readonly bool $shadow = false,
        ?AiAllowance $ai = null,
        public readonly array $cursor = [],
        public readonly ?string $kind = null,
        public readonly array $scope = [],
        array $changed = [],
        array $proposed = [],
        array $acted = [],
    ) {
        $this->deadline = $deadline === null ? null : CarbonImmutable::instance($deadline);
        $this->ai = $ai ?? AiAllowance::none();
        $this->changed = array_values($changed);
        $this->proposed = array_values($proposed);
        $this->acted = array_values($acted);
    }

    /** Whether there's time left for more work (at least $seconds of it); always, with no deadline. */
    public function hasTime(int $seconds = 0): bool
    {
        return $this->deadline === null || CarbonImmutable::now()->addSeconds($seconds)->lt($this->deadline);
    }

    /**
     * Hand refs on to the steps after this one: 'changed', 'proposed' or 'acted'. Only valid refs are kept, each once,
     * up to HANDOFF_MAX.
     */
    public function handOff(string $list, string ...$refs): void
    {
        if (! in_array($list, ['changed', 'proposed', 'acted'], true)) {
            return;
        }
        $kept = $this->{$list};
        foreach ($refs as $ref) {
            if (count($kept) >= self::HANDOFF_MAX) {
                break;
            }
            if (Ref::valid($ref) && ! in_array($ref, $kept, true)) {
                $kept[] = $ref;
            }
        }
        $this->{$list} = $kept;
    }

    /** @return array{changed:list<string>, proposed:list<string>, acted:list<string>} */
    public function handOffs(): array
    {
        return ['changed' => $this->changed, 'proposed' => $this->proposed, 'acted' => $this->acted];
    }
}
