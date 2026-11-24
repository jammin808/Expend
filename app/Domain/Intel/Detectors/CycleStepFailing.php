<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\FindingDraft;
use App\Models\CycleRun;
use App\Models\CycleRunStep;

/**
 * A step of the nightly cycle that failed (docs/intelligence-cycle.md 6.4: health.step:<key>): it was tried three
 * times in its last run and didn't work, or it stopped the worker three times (CycleRunner::housekeep()). One finding per step (key health:step:{step}), in the health group, with the
 * number of runs running it has failed in its meta ('nights'): important after one, critical from two (the Ranker).
 *
 * It reads the run log of the scheduled runs (nightly and morning), newest first: a run where the step failed adds to
 * its streak, one where it worked ends it, and one where it didn't run (skipped in a trial run, or because a step it
 * needs failed) leaves it as it was. The error itself isn't repeated here (it may quote what was being read): the run
 * log at /intel/runs shows it. Only reads.
 */
final class CycleStepFailing implements Detector
{
    public const CODE = 'health.cycle_step';

    public const VERSION = 1;

    /** How many runs of each kind are looked back over. */
    public const LOOK_BACK = 14;

    private Coverage $coverage = Coverage::Failed;

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $streaks = [];
        foreach (['nightly', 'morning'] as $kind) {
            $runs = CycleRun::query()->where('kind', $kind)->whereNotNull('finished_at')->orderByDesc('id')->limit(self::LOOK_BACK)->get(['id', 'run_key', 'kind']);
            $steps = CycleRunStep::query()->whereIn('cycle_run_id', $runs->pluck('id'))->get(['cycle_run_id', 'step', 'status', 'attempts', 'finished_at'])->groupBy('step');
            foreach ($steps as $step => $rows) {
                $byRun = $rows->keyBy('cycle_run_id');
                $nights = 0;
                $failedIn = [];
                foreach ($runs as $run) {
                    $status = $byRun[$run->id]->status ?? null;
                    if ($status === 'failed') {
                        $nights++;
                        $failedIn[] = $run;
                    } elseif ($status === 'ok' || $status === 'budget') {
                        break;
                    } elseif ($nights === 0 && $status !== null && $status !== 'skipped') {
                        break; // still under way, or carrying on: not failed (yet)
                    }
                }
                if ($nights > 0 && $nights >= ($streaks[$step]['nights'] ?? 0)) {
                    $streaks[$step] = ['nights' => $nights, 'kind' => $kind, 'runs' => $failedIn];
                }
            }
        }
        $drafts = [];
        foreach ($streaks as $step => $s) {
            $latest = $s['runs'][0];
            $what = ucfirst(CycleRunner::stepWords($step));
            $when = $s['kind'] === 'morning' ? 'mornings' : 'nights';
            $drafts[] = new FindingDraft(
                key: 'health:step:'.$step,
                code: self::CODE,
                group: 'health',
                priority: 1,
                title: $s['nights'] >= 2 ? "{$what} has failed {$s['nights']} {$when} running" : $what.($s['kind'] === 'morning' ? ' failed this morning' : ' failed overnight'),
                detail: 'It was tried '.CycleRunner::TRIES.' times in the '.($s['kind'] === 'morning' ? 'morning run' : 'nightly check').' and didn’t work; the rest of the run carried on, '
                    .'and it’s tried again in the next one. The run log shows what went wrong (php artisan ledger:cycle status on the server).',
                href: '/intel/runs',
                subjectRef: 'run:'.$latest->id,
                evidence: array_map(fn (CycleRun $r) => 'run:'.$r->id, $s['runs']),
                direction: 'none',
                meta: ['nights' => $s['nights'], 'step' => $step],
                detectorVersion: self::VERSION,
            );
        }
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }
}
