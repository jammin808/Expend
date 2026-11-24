<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Jobs\JobFinder;
use App\Domain\Verification\Verifier;
use App\Models\JobCandidate;

/**
 * relate.jobs (docs/intelligence-cycle.md 4.2): the job finder, as ledger:find-jobs runs it (the AI's view of calendar
 * entries the rules can't call, then all the rules), in parts that each fit a step job's time:
 *  1. asking: the AI is asked about at most AI_PER_STEP entries a job (JobFinder::ask(), which links nothing); while
 *     more are waiting, and the last turn got somewhere, it returns 'more' and the next tick carries on;
 *  2. the job finder's run, once, with every answer (no new questions), recorded as one run;
 *  3. the possible jobs still open, checked against their sources, as the command does.
 * It holds the lock ledger:find-jobs and the queued FindJobs share; while one of them is running, it waits for the
 * next tick. Its AI calls are 'discovery', held to the run's allowance: when that runs out the rules still run with
 * the answers so far, and the step ends with 'budget' (the rest waits for the next night).
 */
final class RelateJobs implements CycleStep
{
    public const KEY = 'relate.jobs';

    /** Calendar entries asked about in one go (the command asks about up to JobFinder::AI_LIMIT at once). */
    public const AI_PER_STEP = 15;

    /** Seconds the job finder's run (part 2) is started with at least: otherwise it waits for the next job. */
    public const RUN_SECONDS = 45;

    public function __construct(private JobFinder $finder, private Verifier $verifier) {}

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
        $useAi = JobFinder::aiAllowed();
        $c = ['asked' => (bool) ($ctx->cursor['asked'] ?? ! $useAi), 'ai_checked' => (int) ($ctx->cursor['ai_checked'] ?? 0),
            'ai_rejected' => (int) ($ctx->cursor['ai_rejected'] ?? 0), 'refused' => (bool) ($ctx->cursor['refused'] ?? false)]
            + (isset($ctx->cursor['ai_left']) ? ['ai_left' => (int) $ctx->cursor['ai_left']] : []);
        $waiting = fn () => StepOutcome::more($c, ['waiting_for' => 'another run of the job finder'] + self::counts($c));

        if (! $c['asked']) {
            [$got, $r] = JobFinder::exclusively(fn () => $ctx->ai->within('discovery', fn () => $this->finder->ask(self::AI_PER_STEP)));
            if (! $got) {
                return $waiting(); // ledger:find-jobs or FindJobs is running: carry on at the next tick
            }
            $before = $c['ai_left'] ?? null;
            $c['ai_checked'] += (int) $r['ai_checked'];
            $c['ai_rejected'] += (int) $r['ai_rejected'];
            $c['ai_left'] = (int) $r['ai_left'];
            $c['refused'] = $ctx->ai->refused();
            // More to ask, and the last turn got somewhere (an entry the AI keeps leaving out isn't asked for ever).
            if (! $c['refused'] && $c['ai_left'] > 0 && ($before === null || $c['ai_left'] < $before)) {
                return StepOutcome::more($c, self::counts($c));
            }
            $c['asked'] = true;
            if (! $ctx->hasTime(self::RUN_SECONDS)) {
                return StepOutcome::more($c, self::counts($c));
            }
        }

        [$got, $r] = JobFinder::exclusively(fn () => $this->finder->run(null, $useAi, 0));
        if (! $got) {
            return $waiting();
        }
        $counts = ['linked' => (int) $r['linked'], 'proposed' => (int) $r['proposed']] + self::counts($c + ['ai_left' => (int) $r['ai_left']]);
        $counts += ['open_candidates' => JobCandidate::query()->where('status', 'open')->get()->each(fn ($cand) => $this->verifier->candidate($cand))->count()];

        return $c['refused'] && (int) $r['ai_left'] > 0 ? StepOutcome::budget($counts) : StepOutcome::ok($counts);
    }

    /** @param  array<string, mixed>  $c */
    private static function counts(array $c): array
    {
        return array_intersect_key($c, array_flip(['ai_checked', 'ai_rejected', 'ai_left']));
    }
}
