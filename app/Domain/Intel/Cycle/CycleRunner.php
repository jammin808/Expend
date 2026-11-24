<?php

namespace App\Domain\Intel\Cycle;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\Steps\AcquireHealth;
use App\Domain\Intel\Cycle\Steps\AcquireRetryReads;
use App\Domain\Intel\Cycle\Steps\DetectSync;
use App\Domain\Intel\Cycle\Steps\PreserveSeal;
use App\Domain\Intel\Cycle\Steps\RecommendAlerts;
use App\Domain\Intel\Cycle\Steps\RecommendReport;
use App\Domain\Intel\Cycle\Steps\RelateCategorise;
use App\Domain\Intel\Cycle\Steps\RelateJobs;
use App\Domain\Intel\Cycle\Steps\RelateTransfers;
use App\Domain\Intel\Cycle\Steps\RunDetector;
use App\Domain\Intel\Cycle\Steps\VerifyAuditChain;
use App\Domain\Intel\Cycle\Steps\VerifyBacklog;
use App\Domain\Intel\Cycle\Steps\VerifyReconcile;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Jobs\ReadDocument;
use App\Jobs\RunCycleStep;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The nightly cycle on shared hosting (docs/intelligence-cycle.md section 6). Only the existing cron (schedule:run
 * every minute) and the existing every-minute queue worker run; nothing long runs in the foreground of the scheduler.
 *
 *  - tick() (ledger:cycle tick, every minute) reads a row or two: it starts a run when one is due (nightly from 03:05
 *    London, morning from 07:05), looks after runs that ran out of time or stopped answering, and when a step can run
 *    it queues RunCycleStep for the worker. It never runs a step itself.
 *  - work() (in RunCycleStep, one at a time: lock intel:cycle) runs the run's next steps, in order, within 90 seconds
 *    at night and 30 by day. A step stopped for time saves its cursor and returns 'more'; the next tick queues it again.
 *  - A step that fails is tried three times; a step that needs it (NEEDS) is then skipped, the others still run, and
 *    the run ends 'partial'. A run whose heartbeat is over 15 minutes old (the worker was stopped mid-step) has that
 *    step resumed, at most twice; the third time the step counts as failed (so the to-do list's health check sees it)
 *    and the rest of the run carries on. A run that keeps stopping (RUN_STALLS) is given up ('abandoned'). At the
 *    run's deadline (06:30 for the nightly run) what's unfinished is skipped.
 *  - finish() ends every run with its seal: one cycle.completed audit event, in the transaction that ends the run.
 *
 * The flag intel_cycle (mode()): off (the old schedule only, nothing here runs), shadow (the old schedule still runs,
 * and runs here only look: every step that would change a record is skipped, and what the others would change is
 * counted in the run log), on (runs here do the work, and the old nightly entries are skipped). A step that changes
 * records runs only while the flag is on now, whatever the run started as; the old entries are skipped only when
 * tonight's nightly run is a real one (doesNightlyWork()), so switching during the night neither drops nor doubles
 * the night's work.
 *
 * Every step runs as the system, on the schedule channel, with the run's id (AuditContext), so what it records says so.
 */
final class CycleRunner
{
    public const SETTING = 'intel_cycle';

    public const MODES = ['off', 'shadow', 'on'];

    /** One step job at a time. */
    public const LOCK = 'intel:cycle';

    public const LOCK_SECONDS = 300;

    /** A step job's time: 90 seconds at night, 30 by day (so Telegram presses queued behind it stay quick). */
    public const NIGHT_SECONDS = 90;

    public const DAY_SECONDS = 30;

    /** Tries before a step counts as failed. */
    public const TRIES = 3;

    /** A run that hasn't been heard from for this long has stopped answering. */
    public const STALE_MINUTES = 15;

    /** Times a step that stopped answering is resumed before it counts as failed. */
    public const RESUMES = 2;

    /** Times a run may stop answering in all before it's given up ('abandoned'). */
    public const RUN_STALLS = 6;

    /** Held while a run is asked for, so two requests at once can't both start one. */
    public const REQUEST_LOCK = 'intel:request';

    public const TZ = 'Europe/London';

    /** When each scheduled run is due, London time: from, and the deadline. */
    public const WINDOWS = ['nightly' => ['03:05', '06:30'], 'morning' => ['07:05', '12:00']];

    /** How long a run after an import or "Check now" has. */
    public const SHORT_RUN_MINUTES = 30;

    /** Every detector in the registry (detect.todo, the to-do list's groups, first). */
    public const DETECTORS = 'detect.*';

    /** The steps of each kind of run, in order (section 6.1). M7 to M10 add theirs. */
    public const PLANS = [
        'nightly' => [VerifyAuditChain::KEY, AcquireHealth::KEY, AcquireRetryReads::KEY, RelateTransfers::KEY, RelateCategorise::KEY, RelateJobs::KEY,
            VerifyBacklog::KEY, VerifyReconcile::KEY, self::DETECTORS, DetectSync::KEY, PreserveSeal::KEY],
        'morning' => [AcquireHealth::KEY, self::DETECTORS, DetectSync::KEY, RecommendReport::KEY, RecommendAlerts::KEY, PreserveSeal::KEY],
        'import' => ['detect.todo', DetectSync::KEY, PreserveSeal::KEY],
        'manual' => [self::DETECTORS, DetectSync::KEY, PreserveSeal::KEY],
    ];

    /** A step that needs another to have worked: skipped when that one failed or was skipped. */
    public const NEEDS = [RecommendAlerts::KEY => [DetectSync::KEY]];

    public const STEPS = [
        VerifyAuditChain::KEY => VerifyAuditChain::class, AcquireHealth::KEY => AcquireHealth::class, AcquireRetryReads::KEY => AcquireRetryReads::class,
        RelateTransfers::KEY => RelateTransfers::class, RelateCategorise::KEY => RelateCategorise::class, RelateJobs::KEY => RelateJobs::class,
        VerifyBacklog::KEY => VerifyBacklog::class, VerifyReconcile::KEY => VerifyReconcile::class, DetectSync::KEY => DetectSync::class,
        RecommendReport::KEY => RecommendReport::class, RecommendAlerts::KEY => RecommendAlerts::class, PreserveSeal::KEY => PreserveSeal::class,
    ];

    /** How each step reads to you. */
    public const STEP_WORDS = [
        VerifyAuditChain::KEY => 'the audit log check', AcquireHealth::KEY => 'the check of your connections', AcquireRetryReads::KEY => 'reading what waited for AI budget',
        RelateTransfers::KEY => 'finding transfers between your accounts', RelateCategorise::KEY => 'suggesting what payments are', RelateJobs::KEY => 'the job finder',
        VerifyBacklog::KEY => 'double-checking AI findings', VerifyReconcile::KEY => 'checking accounts against the bank', 'detect.todo' => 'the to-do list’s checks',
        DetectSync::KEY => 'recording what was found', RecommendReport::KEY => 'the morning report', RecommendAlerts::KEY => 'urgent alerts',
        PreserveSeal::KEY => 'sealing the run in the audit log',
    ];

    /**
     * @param  array<string, CycleStep>|null  $steps  steps to use instead of the usual ones, by key (tests)
     * @param  array<string, list<string>>|null  $plans  plans to use instead of PLANS (tests)
     * @param  array<string, list<string>>|null  $needs  instead of NEEDS (tests)
     * @param  int|null  $nightSeconds  a step job's time at night (NIGHT_SECONDS)
     * @param  int|null  $daySeconds  by day (DAY_SECONDS)
     */
    public function __construct(
        private ?array $steps = null,
        private ?array $plans = null,
        private ?array $needs = null,
        private ?int $nightSeconds = null,
        private ?int $daySeconds = null,
    ) {}

    /** The rollout flag: off, shadow or on (off unless set to one of the others). */
    public static function mode(): string
    {
        $mode = Setting::get(self::SETTING);

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /**
     * Whether tonight's nightly work is the cycle's, so the old nightly entries are skipped (their ->skip()): the flag
     * is on, and tonight's nightly run is a real one. When tonight's run is a trial started before the flag was
     * switched on, or there's no run tonight (the tick isn't starting runs), the old entries do the work as before.
     */
    public static function doesNightlyWork(): bool
    {
        if (self::mode() !== 'on') {
            return false;
        }

        return CycleRun::query()->where('run_key', 'nightly:'.CarbonImmutable::now(self::TZ)->format('Y-m-d'))->value('mode') === 'on';
    }

    /**
     * Switch the flag (ledger:cycle mode), audited. Switched off, the runs still going are ended and sealed. Switched
     * away from on, documents waiting for AI budget are marked failed again, as they were before the cycle: only the
     * cycle's nightly run reads them again on its own, so they're left where "Read with AI" and the to-do list offer it.
     *
     * @return array{before:string, after:string, ended:int, released:int}
     */
    public function switchMode(string $to): array
    {
        if (! in_array($to, self::MODES, true)) {
            throw new InvalidArgumentException('Say off, shadow or on.');
        }
        $before = self::mode();
        $out = ['before' => $before, 'after' => $to, 'ended' => 0, 'released' => 0];
        if ($before === $to) {
            return $out;
        }
        Setting::put(self::SETTING, $to);
        AuditLog::record('settings.intel_cycle', 'Settings', ['intel_cycle' => $before], ['intel_cycle' => $to], 'Set on the server with ledger:cycle mode');
        if ($before === 'on') {
            $out['released'] = Document::query()->where('status', ReadDocument::WAITING)->update(['status' => 'failed', 'updated_at' => now()]);
        }
        if ($to === 'off') {
            $out['ended'] = $this->stopAll();
        }

        return $out;
    }

    /** How a step reads to you ("the job finder"). */
    public static function stepWords(string $key): string
    {
        if (isset(self::STEP_WORDS[$key])) {
            return self::STEP_WORDS[$key];
        }

        return str_starts_with($key, 'detect.') ? 'the “'.substr($key, 7).'” check' : $key;
    }

    // ---------------------------------------------------------------- the tick

    /**
     * Every minute: start a run that's due, look after the running ones, and queue the next step that can run. Quick
     * (a few queries) and never runs a step.
     *
     * @return array{mode:string, started:?string, queued:?int}
     */
    public function tick(): array
    {
        $mode = self::mode();
        $out = ['mode' => $mode, 'started' => null, 'queued' => null];
        if ($mode === 'off') {
            return $out;
        }
        $now = CarbonImmutable::now(self::TZ);
        foreach (array_keys(self::WINDOWS) as $kind) {
            if ($this->isDue($kind, $now) && ! CycleRun::query()->where('run_key', $this->keyFor($kind, $now))->exists()) {
                $out['started'] = $this->start($kind, mode: $mode)?->run_key ?? $out['started'];
            }
        }
        foreach (CycleRun::query()->where('status', 'running')->orderBy('id')->get() as $run) {
            $this->housekeep($run);
            if ($run->isFinished()) {
                continue;
            }
            if ($this->next($run) !== null) {
                RunCycleStep::dispatch($run->id);
                $out['queued'] = $run->id;
                break;
            }
        }

        return $out;
    }

    /** Whether a scheduled run of $kind is due at $now (London): within its window. */
    public function isDue(string $kind, CarbonImmutable $now): bool
    {
        [$from, $until] = self::WINDOWS[$kind] ?? [null, null];
        if ($from === null) {
            return false;
        }
        $time = $now->tz(self::TZ)->format('H:i');

        return $time >= $from && $time < $until;
    }

    /** A scheduled run's key: its kind and the London date (nightly:2026-12-14). */
    public function keyFor(string $kind, ?CarbonImmutable $at = null): string
    {
        return $kind.':'.($at ?? CarbonImmutable::now())->tz(self::TZ)->format('Y-m-d');
    }

    // ---------------------------------------------------------------- starting runs

    /**
     * Start a run, with its steps waiting. Null when the cycle is off, or a run with that key exists already.
     *
     * @param  list<string>  $scope  refs the run is about (an import run's batch and account)
     */
    public function start(string $kind, ?string $key = null, array $scope = [], ?string $mode = null, ?CarbonImmutable $deadline = null): ?CycleRun
    {
        $mode ??= self::mode();
        if (! in_array($mode, CycleRun::MODES, true) || ! in_array($kind, CycleRun::KINDS, true)) {
            return null;
        }
        $now = CarbonImmutable::now();
        $key = mb_substr($key ?? (isset(self::WINDOWS[$kind]) ? $this->keyFor($kind, $now) : $kind.':'.strtoupper((string) Str::ulid())), 0, 40);
        if (CycleRun::query()->where('run_key', $key)->exists()) {
            return null;
        }
        $deadline ??= isset(self::WINDOWS[$kind])
            ? CarbonImmutable::parse($now->tz(self::TZ)->format('Y-m-d').' '.self::WINDOWS[$kind][1], self::TZ)
            : $now->addMinutes(self::SHORT_RUN_MINUTES);
        $steps = $this->plan($kind);
        try {
            return DB::transaction(function () use ($key, $kind, $mode, $now, $deadline, $steps, $scope) {
                $run = CycleRun::query()->create([
                    'run_key' => $key, 'kind' => $kind, 'mode' => $mode, 'status' => 'running', 'started_at' => $now, 'heartbeat_at' => $now, 'deadline_at' => $deadline->setTimezone((string) config('app.timezone')),
                    'ai_budget_micro' => $kind === 'nightly' ? AiAllowance::forNight($now) : 0, 'ai_spent_micro' => 0,
                ]);
                $seq = 0;
                foreach ($steps as $stepKey => $step) {
                    CycleRunStep::query()->create(['cycle_run_id' => $run->id, 'step' => $stepKey, 'stage' => $step->stage()->value, 'seq' => min(255, ++$seq),
                        'status' => 'pending', 'scope' => $scope !== [] ? array_values($scope) : null]);
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // started at the same moment by another tick
        }
    }

    /**
     * A run started by something that happened (an import) or by you ("Check now"), queued at once rather than at the
     * next tick. Null when the cycle is off.
     *
     * A run already going that will record findings from the records as they are by then (its detect.sync hasn't
     * started, and it's in the mode the cycle is in now) covers the request, and is given instead of a new one: so a
     * double press, several statements uploaded one after another, or "Check now" during the nightly run make no
     * second run. An import joining an import run adds its batch and account to the scope of the steps not started.
     * Asked for one at a time (REQUEST_LOCK), so two at once can't both start one.
     *
     * @param  list<string>  $scope
     */
    public function request(string $kind, ?string $key = null, array $scope = []): ?CycleRun
    {
        if (self::mode() === 'off') {
            return null;
        }
        try {
            $run = Cache::lock(self::REQUEST_LOCK, 10)->block(5, fn () => $this->covering($kind, $scope) ?? $this->start($kind, $key, $scope));
        } catch (LockTimeoutException) {
            return $this->covering($kind, $scope); // another request is starting one right now
        }
        if ($run && $run->wasRecentlyCreated) {
            try {
                RunCycleStep::dispatch($run->id);
            } catch (Throwable $e) {
                report($e); // the next tick queues it
            }
        }

        return $run;
    }

    /**
     * The run going now that covers a request for a run of $kind: one in the current mode, with a few minutes left
     * before its deadline, whose detect.sync is still to run. Only an import or "Check now" is covered.
     *
     * @param  list<string>  $scope
     */
    private function covering(string $kind, array $scope): ?CycleRun
    {
        if (! in_array($kind, ['import', 'manual'], true)) {
            return null;
        }
        $mode = self::mode();
        foreach (CycleRun::query()->where('status', 'running')->orderByDesc('id')->get() as $run) {
            if ($run->mode !== $mode || ($run->deadline_at !== null && CarbonImmutable::instance($run->deadline_at)->lt(CarbonImmutable::now()->addMinutes(5)))) {
                continue; // a trial records nothing; one about to be cut short may never get to its detect.sync
            }
            $steps = CycleRunStep::query()->where('cycle_run_id', $run->id)->orderBy('seq')->get();
            $sync = $steps->firstWhere('step', DetectSync::KEY);
            if (! $sync || ! in_array($sync->status, ['pending', 'more'], true)) {
                continue;
            }
            if ($kind === 'import' && $run->kind === 'import' && $scope !== []) {
                foreach ($steps as $s) {
                    if ($s->status === 'pending' && $s->attempts === 0) {
                        $s->forceFill(['scope' => array_values(array_unique([...(array) ($s->scope ?? []), ...$scope]))])->save();
                    }
                }
            }

            return $run;
        }

        return null;
    }

    /**
     * The steps of a run of $kind, by key, in order.
     *
     * @return array<string, CycleStep>
     */
    public function plan(string $kind): array
    {
        $out = [];
        foreach ($this->plans[$kind] ?? self::PLANS[$kind] ?? [] as $key) {
            if ($key === self::DETECTORS) {
                foreach (app(Registry::class)->all() as $detector) {
                    $step = $this->steps[RunDetector::keyFor($detector)] ?? new RunDetector($detector);
                    $out[$step->key()] = $step;
                }

                continue;
            }
            if ($step = $this->step($key)) {
                $out[$key] = $step;
            }
        }

        return $out;
    }

    /** The step with this key, if there is one. */
    public function step(string $key): ?CycleStep
    {
        if (isset($this->steps[$key])) {
            return $this->steps[$key];
        }
        if (isset(self::STEPS[$key])) {
            return app(self::STEPS[$key]);
        }
        if (str_starts_with($key, 'detect.')) {
            foreach (app(Registry::class)->all() as $detector) {
                if (RunDetector::keyFor($detector) === $key) {
                    return new RunDetector($detector);
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- running steps

    /**
     * Run a run's next steps, in order, for up to a step job's time (in RunCycleStep, holding the cycle's lock). A step
     * is started only while most of the time is left; one stopped for time ('more') ends the job, and the next tick
     * queues the next one.
     *
     * @param  bool  $wait  false: don't wait for a step's time (ledger:cycle run: the morning report at once)
     * @return list<array{step:string, status:string}>
     */
    public function work(int $runId, bool $wait = true): array
    {
        $started = microtime(true);
        $done = [];
        while (true) {
            $run = CycleRun::query()->find($runId);
            if (! $run || $run->isFinished()) {
                break;
            }
            $this->housekeep($run);
            if ($run->isFinished() || ! ($row = $this->next($run, $wait))) {
                break;
            }
            $budget = $this->seconds($run);
            $elapsed = microtime(true) - $started;
            if ($done !== [] && $elapsed > $budget / 3) {
                break; // the next step starts with a job of its own
            }
            if ($row->step === PreserveSeal::KEY && ! isset($this->steps[PreserveSeal::KEY])) {
                $this->finish($run);
                $done[] = ['step' => $row->step, 'status' => 'ok'];
                break;
            }
            $this->runStep($run, $row, (int) max(1, floor($budget - $elapsed)), $wait);
            $status = (string) $row->fresh()?->status;
            $done[] = ['step' => $row->step, 'status' => $status];
            if (! in_array($status, CycleRunStep::ENDED, true)) {
                break; // stopped for time, waiting, or to be tried again: at the next tick
            }
        }

        return $done;
    }

    /** A step job's time for this run: 90 seconds at night, 30 by day. */
    public function seconds(CycleRun $run): int
    {
        return $run->kind === 'nightly' ? ($this->nightSeconds ?? self::NIGHT_SECONDS) : ($this->daySeconds ?? self::DAY_SECONDS);
    }

    /**
     * The step to run next: the first that hasn't ended, in order (one at a time: none while one is running). A step
     * whose needed step failed or was skipped is skipped; one waiting for its time ('not_before') holds the run until
     * then. The seal comes last, once every other step has ended.
     */
    public function next(CycleRun $run, bool $wait = true): ?CycleRunStep
    {
        $steps = CycleRunStep::query()->where('cycle_run_id', $run->id)->orderBy('seq')->orderBy('id')->get();
        foreach ($steps as $s) {
            if ($s->hasEnded()) {
                continue;
            }
            if ($s->status === 'running') {
                return null;
            }
            $needs = ($this->needs ?? self::NEEDS)[$s->step] ?? [];
            $missing = $steps->first(fn (CycleRunStep $o) => in_array($o->step, $needs, true) && in_array($o->status, ['failed', 'skipped'], true));
            if ($missing) {
                $s->forceFill(['status' => 'skipped', 'finished_at' => now(), 'counts' => ['skipped' => 'dependency'] + (array) ($s->counts ?? []),
                    'error' => 'Not run: '.self::stepWords($missing->step).' didn’t finish.'])->save();

                continue;
            }
            $notBefore = $s->cursor['not_before'] ?? null;
            if ($wait && is_string($notBefore) && CarbonImmutable::parse($notBefore)->isFuture()) {
                return null;
            }

            return $s;
        }

        return null;
    }

    /** Run one step and keep what it came to. */
    private function runStep(CycleRun $run, CycleRunStep $row, int $seconds, bool $wait): StepOutcome
    {
        $step = $this->step($row->step);
        $fresh = $row->status === 'pending';
        $row->forceFill(['status' => 'running', 'started_at' => $row->started_at ?? now(), 'attempts' => min(255, $row->attempts + ($fresh ? 1 : 0))])->save();
        $run->forceFill(['heartbeat_at' => now()])->save();

        $deadline = CarbonImmutable::now()->addSeconds($seconds);
        if ($run->deadline_at !== null && CarbonImmutable::instance($run->deadline_at)->lt($deadline)) {
            $deadline = CarbonImmutable::instance($run->deadline_at);
        }
        $cursor = array_diff_key((array) ($row->cursor ?? []), ['not_before' => true]) + ($wait ? [] : ['now' => true]);
        $hand = (array) (($run->counts ?? [])['handoff'] ?? []);
        // A run does real work only while the flag is on: one started for real and switched to a trial (or off) since
        // goes on as a trial, so the old schedule, running again, is the only one changing records.
        $switched = ! $run->isShadow() && self::mode() !== 'on';
        $ctx = new CycleContext(runId: $run->id, deadline: $deadline, shadow: $run->isShadow() || $switched, ai: new AiAllowance((int) $run->ai_budget_micro, (int) $run->ai_spent_micro),
            cursor: $cursor, kind: $run->kind, scope: array_values((array) ($row->scope ?? [])),
            changed: (array) ($hand['changed'] ?? []), proposed: (array) ($hand['proposed'] ?? []), acted: (array) ($hand['acted'] ?? []));

        if ($step === null) {
            $outcome = StepOutcome::skipped('unknown', 'No longer part of the cycle.');
        } elseif ($step->writes() && $run->isShadow()) {
            $outcome = StepOutcome::skipped('shadow', 'A trial run: this step changes records, so it runs only once the cycle is on.');
        } elseif ($step->writes() && $switched) {
            $outcome = StepOutcome::skipped('mode', 'The cycle was switched to '.(self::mode() === 'off' ? 'off' : 'a trial').' during this run: this step changes records, so the usual nightly work does it instead.');
        } else {
            try {
                $outcome = AuditContext::with('system', fn () => $step->run($ctx), 'schedule', null, $run->id);
            } catch (Throwable $e) {
                report($e);
                $outcome = StepOutcome::failed(class_basename($e).': '.$e->getMessage());
            }
        }
        $this->keep($run, $row, $outcome, $ctx);

        return $outcome;
    }

    /** Keep what a step came to, and the run's heartbeat, AI spend and hand-offs. */
    private function keep(CycleRun $run, CycleRunStep $row, StepOutcome $outcome, CycleContext $ctx): void
    {
        $fields = [
            'counts' => $outcome->counts !== [] ? $outcome->counts : $row->counts,
            'coverage' => $outcome->coverage?->value ?? $row->coverage,
            'detector_version' => $outcome->detectorVersion ?? $row->detector_version,
            'error' => $outcome->error,
        ];
        $fields += match ($outcome->status) {
            'ok' => ['status' => 'ok', 'finished_at' => now(), 'cursor' => null],
            'more' => ['status' => 'more', 'cursor' => ($outcome->cursor ?? []) + ($outcome->notBefore !== null ? ['not_before' => $outcome->notBefore] : [])],
            'budget' => ['status' => 'budget', 'finished_at' => now(), 'cursor' => $outcome->cursor],
            'skipped' => ['status' => 'skipped', 'finished_at' => now()],
            default => $row->attempts < self::TRIES
                ? ['status' => 'pending', 'cursor' => $row->cursor] // tried again at the next tick, from where it got to
                : ['status' => 'failed', 'finished_at' => now()],
        };
        if (is_array($fields['cursor'] ?? null) && $fields['cursor'] === []) {
            $fields['cursor'] = null;
        }
        $row->forceFill($fields)->save();

        $counts = (array) ($run->fresh()?->counts ?? []);
        $hand = $ctx->handOffs();
        $counts['handoff'] = array_filter($hand) ?: null;
        $counts = array_filter($counts, fn ($v) => $v !== null);
        $run->forceFill(['heartbeat_at' => now(), 'ai_spent_micro' => max((int) $run->ai_spent_micro, $ctx->ai->spentMicro()), 'counts' => $counts ?: null])->save();
    }

    // ---------------------------------------------------------------- looking after runs

    /**
     * Look after a running run: past its deadline, what's unfinished is skipped (the seal still runs). A run whose
     * heartbeat is over 15 minutes old has stopped answering: the worker was stopped while a step ran. That step is
     * resumed from its cursor, at most twice (RESUMES); the third time it counts as failed, as a step that threw does
     * three times (a step that needs it is skipped, the rest carries on, and the to-do list's health check sees it). A
     * run that stops answering more than RUN_STALLS times in all is given up ('abandoned').
     */
    public function housekeep(CycleRun $run): void
    {
        if ($run->isFinished()) {
            return;
        }
        $now = CarbonImmutable::now();
        $steps = CycleRunStep::query()->where('cycle_run_id', $run->id)->orderBy('seq')->get();
        $running = $steps->firstWhere('status', 'running');
        $stale = $run->heartbeat_at === null || CarbonImmutable::instance($run->heartbeat_at)->lt($now->subMinutes(self::STALE_MINUTES));
        if ($running && $stale) {
            $counts = (array) ($run->counts ?? []);
            $runStalls = (int) ($counts['resumes'] ?? 0) + 1;
            $stalls = (int) (($running->counts ?? [])['stalls'] ?? 0) + 1;
            $stepCounts = ['stalls' => $stalls] + (array) ($running->counts ?? []);
            if ($stalls > self::RESUMES) {
                $running->forceFill(['status' => 'failed', 'finished_at' => $now, 'counts' => $stepCounts,
                    'error' => 'It stopped answering '.$stalls.' times (the worker was stopped while it ran), so it was given up for this run.'])->save();
            } else {
                // Carried on from its cursor; without one it starts again, and that isn't another try.
                $running->forceFill(['status' => $running->cursor ? 'more' : 'pending', 'attempts' => max(0, $running->attempts - ($running->cursor ? 0 : 1)),
                    'counts' => $stepCounts, 'error' => 'Stopped answering: carried on from where it got to.'])->save();
            }
            if ($runStalls > self::RUN_STALLS) {
                $this->cut($steps->map(fn (CycleRunStep $s) => $s->fresh()), 'abandoned', 'The run stopped answering '.$runStalls.' times, so it was given up.');
                $counts = ['abandoned' => true] + $counts;
            }
            $run->forceFill(['heartbeat_at' => $now, 'counts' => ['resumes' => $runStalls] + $counts])->save();

            return;
        }
        if ($run->deadline_at !== null && CarbonImmutable::instance($run->deadline_at)->lte($now) && ! $running) {
            $this->cut($steps, 'deadline', 'Not finished by '.CarbonImmutable::instance($run->deadline_at)->tz(self::TZ)->format('H:i').': it carries on in the next run.');
        }
    }

    /**
     * End every run still going, sealed, with what's unfinished skipped: the cycle was switched off, and the tick
     * (skipped while it's off) won't look after them. A step job at work finishes its step first (the cycle's lock).
     *
     * @return int how many were ended
     */
    public function stopAll(): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        try {
            $got = (bool) $lock->block(120);
        } catch (LockTimeoutException) {
            $got = false; // ended all the same: what that step does now is kept on its own row
        }
        try {
            $n = 0;
            foreach (CycleRun::query()->where('status', 'running')->orderBy('id')->get() as $run) {
                $this->cut(CycleRunStep::query()->where('cycle_run_id', $run->id)->orderBy('seq')->get(), 'off', 'The cycle was switched off during the run.');
                $this->finish($run);
                $n++;
            }

            return $n;
        } finally {
            if ($got) {
                $lock->release();
            }
        }
    }

    /** Skip every unfinished step but the seal (and a detector's coverage is then capped: it didn't look at everything). */
    private function cut(Collection $steps, string $why, string $text): void
    {
        foreach ($steps as $s) {
            if ($s->hasEnded() || $s->step === PreserveSeal::KEY) {
                continue;
            }
            $s->forceFill(['status' => 'skipped', 'finished_at' => now(), 'counts' => ['skipped' => $why] + (array) ($s->counts ?? []), 'error' => $text]
                + ($s->stage === Stage::Detect->value && $s->status !== 'pending' ? ['coverage' => 'capped'] : []))->save();
        }
    }

    // ---------------------------------------------------------------- the end of a run

    /**
     * End a run and seal it, in one transaction: its status (ok; partial when a step failed or was cut short; failed
     * when every step failed; abandoned), the digest of the findings open now, and one cycle.completed audit event
     * (actor system) carrying the run's id, its counts and the digest. Once only: a run already ended is left alone.
     * A shadow run keeps its digest and writes no audit event.
     */
    public function finish(CycleRun $run): void
    {
        AuditContext::with('system', function () use ($run) {
            DB::transaction(function () use ($run) {
                $run = CycleRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if (! $run || $run->isFinished()) {
                    return;
                }
                $steps = CycleRunStep::query()->where('cycle_run_id', $run->id)->orderBy('seq')->get();
                $this->cut($steps, 'deadline', 'The run ended before this step.');
                $steps = $steps->map(fn (CycleRunStep $s) => $s->fresh());
                $others = $steps->reject(fn (CycleRunStep $s) => $s->step === PreserveSeal::KEY);
                $status = $this->statusOf($run, $others);
                $digest = self::digest();
                $sync = $others->firstWhere('step', DetectSync::KEY)?->counts;
                $summary = array_filter([
                    'steps' => $others->countBy('status')->sortKeys()->all(),
                    'findings' => is_array($sync) ? array_intersect_key($sync, array_flip(['new', 'resolved', 'open', 'unseen', 'kept'])) : null,
                    'ai_spent_micro' => (int) $run->ai_spent_micro,
                ], fn ($v) => $v !== null);
                $audit = null;
                if (! $run->isShadow()) {
                    $audit = AuditLog::record('cycle.completed', $run, null, [
                        'run_id' => $run->id, 'run_key' => $run->run_key, 'kind' => $run->kind, 'mode' => $run->mode, 'status' => $status,
                        'counts' => $summary, 'digest' => $digest,
                    ], null, 'system');
                }
                $seal = $steps->firstWhere('step', PreserveSeal::KEY);
                $seal?->forceFill(['status' => 'ok', 'started_at' => $seal->started_at ?? now(), 'finished_at' => now(), 'attempts' => $seal->attempts + 1,
                    'counts' => array_filter(['digest' => $digest, 'audit_event_id' => $audit?->id]), 'error' => null])->save();
                $run->forceFill(['status' => $status, 'finished_at' => now(), 'heartbeat_at' => now(), 'digest' => $digest, 'seal_audit_id' => $audit?->id,
                    'counts' => array_filter(['summary' => $summary] + (array) ($run->counts ?? []))])->save();
            });
        }, 'schedule', null, $run->id);
        // What the to-do list's health check says about failing steps (CycleStepFailing) is read from the run log, which
        // the list's cache doesn't watch: once a nightly or morning run has ended, the list is worked out afresh.
        if (in_array($run->kind, ['nightly', 'morning'], true)) {
            TodoList::forget();
        }
    }

    private function statusOf(CycleRun $run, Collection $others): string
    {
        if (($run->counts['abandoned'] ?? false) === true) {
            return 'abandoned';
        }
        if ($others->isNotEmpty() && $others->every(fn (CycleRunStep $s) => $s->status === 'failed')) {
            return 'failed';
        }
        $short = $others->contains(fn (CycleRunStep $s) => $s->status === 'failed'
            || ($s->status === 'skipped' && in_array($s->counts['skipped'] ?? null, ['dependency', 'deadline', 'abandoned', 'off'], true)));

        return $short ? 'partial' : 'ok';
    }

    /**
     * The sha256 of the findings open now (each one's key, code, amount and currency, in key order, canonically): the
     * same digest a year's lock snapshot keeps (FindingsRegistry::openDigest()), so a seal and a lock can be compared.
     */
    public static function digest(): string
    {
        return FindingsRegistry::openDigest()['digest'];
    }
}
