<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Verification\Verifier;

/**
 * verify.backlog (docs/intelligence-cycle.md 4.3): double-checks AI findings against their sources again, as
 * ledger:recheck does (fetching emails again from Gmail, and second reads of scans), in turns so a step job keeps to
 * its time: each turn checks a few of each kind (Verifier::recheck(TURN_LIMIT)) with at most one second read, which is
 * where the time goes. Turns run while the job has time; the rest carries on at the next tick ('more'), up to TURNS
 * turns a night (about as many checks as the command's 80, and its five second reads). It stops early once there's
 * nothing left to check. The second reads are 'verify' AI calls, held to the run's allowance: once it runs out, the
 * turns left check without them, and the step ends with 'budget'.
 */
final class VerifyBacklog implements CycleStep
{
    public const KEY = 'verify.backlog';

    /** Turns a night. */
    public const TURNS = 5;

    /** Verifier::recheck()'s limit for one turn: five of each kind. */
    public const TURN_LIMIT = 25;

    /** Second reads of scans in one turn (five a night, as ledger:recheck). */
    public const SECOND_READS = 1;

    /** Seconds a turn may take at most, so one is started only with this much time left. */
    public const TURN_SECONDS = 40;

    public function __construct(private Verifier $verifier) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Verify;
    }

    public function writes(): bool
    {
        return true;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $c = ['turns' => (int) ($ctx->cursor['turns'] ?? 0), 'checked' => (int) ($ctx->cursor['checked'] ?? 0), 'warnings' => (int) ($ctx->cursor['warnings'] ?? 0),
            'refused' => (bool) ($ctx->cursor['refused'] ?? false)];
        $first = true;
        while ($c['turns'] < self::TURNS) {
            if (! $first && ! $ctx->hasTime(self::TURN_SECONDS)) {
                return StepOutcome::more($c, self::counts($c));
            }
            $first = false;
            $reads = $c['refused'] ? 0 : self::SECOND_READS;
            $r = $ctx->ai->within('verify', fn () => $this->verifier->recheck(self::TURN_LIMIT, $reads, true));
            $c['turns']++;
            $c['checked'] += (int) $r['checked'];
            $c['warnings'] += (int) $r['warnings'];
            $c['refused'] = $c['refused'] || $ctx->ai->refused();
            if ((int) $r['checked'] === 0) {
                break; // nothing left to check
            }
        }

        return $c['refused'] ? StepOutcome::budget(self::counts($c)) : StepOutcome::ok(self::counts($c));
    }

    /** @param  array{turns:int, checked:int, warnings:int, refused:bool}  $c */
    private static function counts(array $c): array
    {
        return ['checked' => $c['checked'], 'warnings' => $c['warnings'], 'turns' => $c['turns']];
    }
}
