<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Intel\Cycle\Steps\PreserveSeal;
use App\Domain\Intel\Cycle\Steps\RelateCategorise;
use App\Domain\Intel\Detectors\CycleStepFailing;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Matching\Categoriser;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Jobs\RunCycleStep;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The nightly cycle's runner (docs/intelligence-cycle.md 6.1 to 6.4, M5): the tick only queues, a run is made once a
 * night, steps carry on from their cursor, a failing step leaves the others running, a run that stopped answering is
 * resumed and then given up, every run ends with one seal and the audit chain still verifies, and a second run changes
 * nothing.
 */
class CycleRunnerTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('job_finder_auto', false);
        Setting::put('intel_cycle', 'on');
        Http::fake();
        $this->at('2026-11-24 03:10:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** London time. */
    private function at(string $london): void
    {
        $utc = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    private function tx(string $date, int $pence, string $description, string $account = 'Starling'): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => $account], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => $account.'.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'counterparty' => null, 'merchant_key' => strtoupper($description), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    private function overdueInvoice(string $title = 'Arena show'): Invoice
    {
        $job = WorkJob::query()->create(['title' => $title, 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'po_number' => 'PO-'.$this->n++]);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        $issued = app(InvoiceService::class)->issue($invoice->fresh(), '2026-09-15')->fresh();
        // Sent (S10: an invoice not sent is listed to send, never as overdue).
        $issued->forceFill(['sent_at' => '2026-09-15 10:00:00', 'sent_to' => 'accounts@acme.example'])->save();

        return $issued->fresh();
    }

    /** Work a run through to its end, as the worker would (each call is one RunCycleStep). */
    private function workThrough(CycleRun $run, ?CycleRunner $runner = null, int $max = 60): CycleRun
    {
        $runner ??= app(CycleRunner::class);
        for ($i = 0; $i < $max && ! $run->fresh()->isFinished(); $i++) {
            $runner->work($run->id);
        }

        return $run->fresh();
    }

    /** A step for these tests: it runs $work. */
    private function step(string $key, callable $work, bool $writes = false): CycleStep
    {
        return new class($key, $work, $writes) implements CycleStep
        {
            public int $calls = 0;

            public function __construct(private string $k, private $work, private bool $w) {}

            public function key(): string
            {
                return $this->k;
            }

            public function stage(): Stage
            {
                return Stage::Detect;
            }

            public function writes(): bool
            {
                return $this->w;
            }

            public function run(CycleContext $ctx): StepOutcome
            {
                $this->calls++;

                return ($this->work)($ctx);
            }
        };
    }

    public function test_the_tick_starts_the_night_and_queues_a_step_but_never_runs_one(): void
    {
        Bus::fake([RunCycleStep::class]);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $started = microtime(true);
        $this->artisan('ledger:cycle tick')->assertSuccessful();
        $took = microtime(true) - $started;

        $run = CycleRun::query()->sole();
        $this->assertSame(['nightly:2026-11-24', 'nightly', 'on', 'running'], [$run->run_key, $run->kind, $run->mode, $run->status]);
        $this->assertSame(['pending'], CycleRunStep::query()->where('cycle_run_id', $run->id)->pluck('status')->unique()->values()->all(), 'No step ran');
        Bus::assertDispatched(RunCycleStep::class, fn (RunCycleStep $job) => $job->runId === $run->id);
        $this->assertLessThan(1.0, $took, 'Under a second');
        $this->assertLessThan(80, $queries, 'Starting the run is a handful of inserts');

        // The next minute: no second run, a few reads, and the step queued again (the worker hasn't got to it yet).
        $queries = 0;
        $this->at('2026-11-24 03:11:00');
        $this->artisan('ledger:cycle tick')->assertSuccessful();
        $this->assertSame(1, CycleRun::query()->count());
        $this->assertLessThan(15, $queries);
        $this->assertSame(['pending'], CycleRunStep::query()->pluck('status')->unique()->values()->all());
    }

    public function test_runs_are_due_in_their_london_windows_once_a_day(): void
    {
        Bus::fake([RunCycleStep::class]);
        $this->at('2026-11-24 02:59:00');
        $this->artisan('ledger:cycle tick');
        $this->assertSame(0, CycleRun::query()->count(), 'Not before 03:05');
        $this->at('2026-11-24 03:05:00');
        $this->artisan('ledger:cycle tick');
        $this->at('2026-11-24 04:30:00');
        $this->artisan('ledger:cycle tick');
        $this->at('2026-11-24 07:06:00');
        $this->artisan('ledger:cycle tick');
        $this->assertSame(['nightly:2026-11-24', 'morning:2026-11-24'], CycleRun::query()->orderBy('id')->pluck('run_key')->all());
        // In summer, 03:05 London is 02:05 UTC: still due on London time.
        $this->at('2026-07-01 03:06:00');
        $this->artisan('ledger:cycle tick');
        $this->assertTrue(CycleRun::query()->where('run_key', 'nightly:2026-07-01')->exists());
    }

    public function test_the_tick_does_nothing_while_the_cycle_is_off(): void
    {
        Bus::fake([RunCycleStep::class]);
        Setting::put('intel_cycle', 'off');
        $this->artisan('ledger:cycle tick')->assertSuccessful();
        $this->assertSame(0, CycleRun::query()->count());
        Bus::assertNotDispatched(RunCycleStep::class);
        $this->assertNull(app(CycleRunner::class)->request('manual'), '"Check now" needs the cycle on or on trial');
    }

    public function test_a_night_does_the_nightly_work_as_the_system_and_seals_the_run(): void
    {
        Setting::put('intel_audit_context', true);
        $this->tx('2026-11-20', -12000, 'TO SAVINGS', 'Starling');
        $this->tx('2026-11-20', 12000, 'FROM CURRENT', 'Savings');
        $this->tx('2026-11-21', -850, 'TRAINLINE');
        $invoice = $this->overdueInvoice();
        $before = AuditEvent::query()->max('id');

        $run = $this->workThrough(app(CycleRunner::class)->start('nightly'));

        $this->assertSame('ok', $run->status, json_encode(CycleRunStep::query()->where('cycle_run_id', $run->id)->get(['step', 'status', 'error'])->toArray()));
        $steps = CycleRunStep::query()->where('cycle_run_id', $run->id)->pluck('status', 'step')->all();
        foreach (['verify.audit_chain', 'acquire.health', 'acquire.retry_reads', 'relate.transfers', 'relate.categorise', 'relate.jobs', 'verify.backlog',
            'verify.reconcile', 'detect.todo', 'detect.sync', 'preserve.seal'] as $key) {
            $this->assertSame('ok', $steps[$key] ?? null, $key);
        }
        // The findings are recorded, with the run that found them.
        $finding = Finding::query()->where('key', 'invoice_overdue:'.$invoice->id)->sole();
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertSame($run->id, FindingEvent::query()->where('finding_id', $finding->id)->value('run_id'));
        // What it changed is recorded as the system's (or the matcher's), with the run's id, never as you.
        $events = AuditEvent::query()->where('id', '>', $before ?? 0)->get();
        $this->assertNotEmpty($events);
        $this->assertNotContains('you', $events->pluck('actor')->unique()->all());
        $this->assertSame([$run->id], $events->pluck('run_id')->unique()->values()->all());
        $this->assertSame(['schedule'], $events->pluck('channel')->unique()->values()->all());
        // One seal, carrying the run's id, its counts and the digest of the findings open now.
        $seal = AuditEvent::query()->where('action', 'cycle.completed')->sole();
        $this->assertSame(['system', 'CycleRun', $run->id], [$seal->actor, $seal->subject_type, (int) $seal->subject_id]);
        $this->assertSame($run->id, $seal->after['run_id']);
        $this->assertSame(CycleRunner::digest(), $seal->after['digest']);
        $this->assertSame($run->digest, $seal->after['digest']);
        $this->assertSame('ok', $seal->after['status']);
        $this->assertGreaterThanOrEqual(1, $seal->after['counts']['findings']['new']);
        $this->assertSame(Finding::query()->whereNull('resolved_at')->count(), $seal->after['counts']['findings']['open']);
        $this->assertSame($seal->id, $run->seal_audit_id);
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_the_seal_is_written_once_and_the_chain_verifies(): void
    {
        $runner = app(CycleRunner::class);
        $run = $this->workThrough($runner->start('manual'));
        $runner->finish($run);
        $runner->work($run->id);
        $this->artisan('ledger:cycle tick');

        $this->assertSame(1, AuditEvent::query()->where('action', 'cycle.completed')->where('subject_id', $run->id)->count());
        $this->assertSame(['ok' => true, 'checked' => AuditEvent::query()->count(), 'broken_at' => null], AuditLog::verify());
        $this->assertSame('ok', CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', PreserveSeal::KEY)->value('status'));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->tx('2026-11-20', -12000, 'TO SAVINGS', 'Starling');
        $this->tx('2026-11-20', 12000, 'FROM CURRENT', 'Savings');
        $this->tx('2026-11-21', -850, 'TRAINLINE');
        $this->overdueInvoice();
        $this->workThrough(app(CycleRunner::class)->start('nightly'));

        $snapshot = fn () => [
            Allocation::query()->orderBy('id')->get()->map(fn ($a) => $a->only(['id', 'bank_transaction_id', 'kind', 'category_id', 'amount_pence', 'decided_by', 'confirmed_at']))->all(),
            BankTransaction::query()->orderBy('id')->get(['id', 'review_status', 'transfer_pair_id'])->toArray(),
            Finding::query()->orderBy('id')->get(['id', 'key', 'state', 'amount_pence', 'resolved_at'])->toArray(),
            FindingEvent::query()->count(),
        ];
        $was = $snapshot();
        $events = AuditEvent::query()->max('id');

        // The same night again, by hand, under a new key.
        $this->artisan('ledger:cycle run nightly --force')->assertSuccessful();

        $this->assertSame(2, CycleRun::query()->where('kind', 'nightly')->count());
        $this->assertSame($was, $snapshot(), 'Nothing in the books or the findings changed');
        $this->assertSame(['cycle.completed', 'jobs.finder_run'], AuditEvent::query()->where('id', '>', $events)->pluck('action')->unique()->sort()->values()->all(),
            'Only the run log itself: its seal, and the job finder noting its run');
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_a_step_stopped_by_its_time_carries_on_from_its_cursor_at_the_next_tick(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->tx('2026-11-'.(10 + $i), -100 * $i, 'SHOP '.$i)->id;
        }
        $runner = new CycleRunner(steps: [RelateCategorise::KEY => new RelateCategorise(app(Categoriser::class), 2)], plans: ['nightly' => [RelateCategorise::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly', deadline: CarbonImmutable::now()->addYear());
        $step = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', RelateCategorise::KEY)->sole();

        // The clock moves on 100 seconds every time it's read: the step's 90 seconds are up after its first chunk.
        $clock = CarbonImmutable::now();
        $tick = function () use (&$clock) {
            $clock = $clock->addSeconds(100);

            return $clock;
        };
        Carbon::setTestNow(fn () => Carbon::instance($tick()));
        CarbonImmutable::setTestNow(fn () => $tick());

        $runner->work($run->id);
        $this->assertSame('more', $step()->status);
        $this->assertSame($ids[1], $step()->cursor['last_id'], 'Saved where it got to');
        $this->assertSame(2, $step()->counts['looked_at']);

        Bus::fake([RunCycleStep::class]);
        $this->artisan('ledger:cycle tick');
        Bus::assertDispatched(RunCycleStep::class, fn (RunCycleStep $j) => $j->runId === $run->id);

        $runner->work($run->id);
        $this->assertSame($ids[3], $step()->cursor['last_id'], 'Carried on after the last one done, not from the start');
        $this->assertSame(4, $step()->counts['looked_at']);
        $this->workThrough($run, $runner);
        $this->assertSame('ok', $step()->status);
        $this->assertSame(5, $step()->counts['looked_at'], 'Each payment looked at once');
        $this->assertSame(1, $step()->attempts, 'Carrying on isn’t another try');
    }

    public function test_a_failing_step_doesnt_stop_the_others_and_raises_a_health_finding(): void
    {
        $boom = $this->step('test.boom', fn () => throw new RuntimeException('Something broke'));
        $after = $this->step('test.after', fn () => StepOutcome::ok(['ran' => true]));
        $other = $this->step('test.other', fn () => StepOutcome::ok(['ran' => true]));
        $runner = new CycleRunner(steps: ['test.boom' => $boom, 'test.after' => $after, 'test.other' => $other],
            plans: ['nightly' => ['test.boom', 'test.after', 'test.other', PreserveSeal::KEY]], needs: ['test.after' => ['test.boom']]);
        $this->app->instance(CycleRunner::class, $runner);
        $this->assertSame([], array_column(TodoList::cached()['items'], 'key'), 'The list is kept before the run');

        $run = $this->workThrough($runner->start('nightly'), $runner);

        $steps = CycleRunStep::query()->where('cycle_run_id', $run->id)->get()->keyBy('step');
        $this->assertSame(['failed', 3], [$steps['test.boom']->status, $steps['test.boom']->attempts], 'Tried three times');
        // The kept list doesn't watch the run log: the run's end starts it afresh, so the failure shows at once.
        $this->assertContains('health:step:test.boom', array_column(TodoList::cached()['items'], 'key'));
        $this->assertStringContainsString('Something broke', $steps['test.boom']->error);
        $this->assertSame(['skipped', 'dependency'], [$steps['test.after']->status, $steps['test.after']->counts['skipped']], 'The step that needs it is skipped');
        $this->assertSame(0, $after->calls);
        $this->assertSame('ok', $steps['test.other']->status, 'The one that doesn’t need it still runs');
        $this->assertSame('partial', $run->status);
        $this->assertSame('partial', AuditEvent::query()->where('action', 'cycle.completed')->sole()->after['status']);

        // health.step: one night, then two nights running.
        $drafts = (new CycleStepFailing)->detect(new CycleContext);
        $this->assertCount(1, $drafts);
        $this->assertSame(['health:step:test.boom', 'health.cycle_step', 'health', 1], [$drafts[0]->key, $drafts[0]->code, $drafts[0]->group, $drafts[0]->meta['nights']]);
        $this->assertSame('run:'.$run->id, $drafts[0]->subjectRef);
        $this->assertStringNotContainsString('Something broke', $drafts[0]->title.$drafts[0]->detail, 'The error stays in the run log');

        $this->at('2026-11-25 03:10:00');
        $this->workThrough($runner->start('nightly'), $runner);
        $drafts = (new CycleStepFailing)->detect(new CycleContext);
        $this->assertSame(2, $drafts[0]->meta['nights']);
        $this->assertStringContainsString('2 nights running', $drafts[0]->title);

        // Recorded as a finding in the health group.
        $this->app->instance(Registry::class, new Registry([new CycleStepFailing]));
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'health:step:test.boom')->sole();
        $this->assertSame(['health.cycle_step', 'health', 'open'], [$finding->code, $finding->group, $finding->state]);
        $this->assertSame('critical', $finding->severity, 'Two nights running: critical, as recorded');

        // Registered with the rest (contract 3): on the to-do list, first and critical, with no wiring of its own.
        $this->app->forgetInstance(Registry::class);
        $this->assertContains(CycleStepFailing::class, array_map(fn ($d) => $d::class, app(Registry::class)->all()));
        $item = collect(TodoList::build()['items'])->firstWhere('key', 'health:step:test.boom');
        $this->assertSame(['health', 1, 'critical', 2], [$item['group'], $item['priority'], $item['severity'], $item['nights']]);

        // It works the next night: the finding goes.
        $runner = new CycleRunner(steps: ['test.boom' => $this->step('test.boom', fn () => StepOutcome::ok())],
            plans: ['nightly' => ['test.boom', PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $this->at('2026-11-26 03:10:00');
        $this->workThrough($runner->start('nightly'), $runner);
        $this->assertSame([], (new CycleStepFailing)->detect(new CycleContext));
    }

    public function test_a_step_that_stopped_answering_is_resumed_twice_then_fails_and_the_run_carries_on(): void
    {
        Bus::fake([RunCycleStep::class]);
        $hang = $this->step('test.hang', fn () => StepOutcome::ok());
        $other = $this->step('test.other', fn () => StepOutcome::ok(['ran' => true]));
        $runner = new CycleRunner(steps: ['test.hang' => $hang, 'test.other' => $other], plans: ['nightly' => ['test.hang', 'test.other', PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        $row = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'test.hang')->sole();
        // The worker started the step and was stopped mid-way: the step stays "running" and the heartbeat goes quiet.
        $stall = function () use ($run, $row) {
            $row()->forceFill(['status' => 'running', 'started_at' => now(), 'cursor' => ['last_id' => 7]])->save();
            $run->fresh()->forceFill(['heartbeat_at' => now()->subMinutes(16)])->save();
        };

        $stall();
        $this->artisan('ledger:cycle tick');
        $this->assertSame(['more', 7, 1], [$row()->status, $row()->cursor['last_id'], $row()->counts['stalls']], 'Resumed from where it got to');
        $this->assertSame(1, $run->fresh()->counts['resumes']);
        Bus::assertDispatched(RunCycleStep::class);

        $stall();
        $this->artisan('ledger:cycle tick');
        $this->assertSame(['more', 2], [$row()->status, $row()->counts['stalls']]);

        // The third time it counts as failed, as a step that threw three times: the rest of the run carries on.
        $stall();
        $this->artisan('ledger:cycle tick');
        $this->assertSame(['failed', 3], [$row()->status, $row()->counts['stalls']]);
        $this->assertStringContainsString('stopped answering 3 times', $row()->error);
        $run = $this->workThrough($run, $runner);
        $this->assertSame('ok', CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'test.other')->value('status'), 'The step that doesn’t need it still runs');
        $this->assertSame('partial', $run->status);
        $this->assertNotNull($run->seal_audit_id, 'Sealed all the same');
        $this->assertSame(0, $hang->calls);
        $this->assertSame(1, $other->calls);

        // Raised as a health finding, like any failing step; critical from the second night (the Ranker).
        $drafts = (new CycleStepFailing)->detect(new CycleContext);
        $this->assertSame(['health:step:test.hang', 1], [$drafts[0]->key, $drafts[0]->meta['nights']]);
        $this->at('2026-11-25 03:10:00');
        $run = $runner->start('nightly');
        $row = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'test.hang')->sole();
        foreach (range(1, 3) as $i) {
            $row()->forceFill(['status' => 'running', 'started_at' => now()])->save();
            $run->fresh()->forceFill(['heartbeat_at' => now()->subMinutes(16)])->save();
            $runner->tick();
        }
        $this->assertSame('failed', $row()->status, 'Without a cursor it starts again, and still fails the third time');
        $this->workThrough($run, $runner);
        $drafts = (new CycleStepFailing)->detect(new CycleContext);
        $this->assertSame(2, $drafts[0]->meta['nights'], 'Two nights running');
    }

    public function test_a_run_that_keeps_stopping_is_given_up_and_sealed(): void
    {
        Bus::fake([RunCycleStep::class]);
        $steps = [];
        foreach (range(1, 4) as $i) {
            $steps['test.hang'.$i] = $this->step('test.hang'.$i, fn () => StepOutcome::ok());
        }
        $runner = new CycleRunner(steps: $steps, plans: ['nightly' => [...array_keys($steps), PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        // Every step it starts, the worker is stopped: CycleRunner::RUN_STALLS times in all, then it's given up.
        for ($i = 0; $i <= CycleRunner::RUN_STALLS; $i++) {
            $next = $runner->next($run->fresh());
            $this->assertNotNull($next);
            $next->forceFill(['status' => 'running', 'started_at' => now()])->save();
            $run->fresh()->forceFill(['heartbeat_at' => now()->subMinutes(16)])->save();
            $runner->tick();
        }
        $run = $this->workThrough($run, $runner);
        $this->assertSame('abandoned', $run->status);
        $this->assertSame(CycleRunner::RUN_STALLS + 1, $run->counts['resumes']);
        $this->assertSame(['failed', 'failed', 'skipped', 'skipped'], CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'like', 'test.%')->orderBy('seq')->pluck('status')->all());
        $this->assertNotNull($run->seal_audit_id, 'Sealed all the same');
    }

    public function test_past_its_deadline_whats_unfinished_is_skipped_and_the_run_ends_partial(): void
    {
        $slow = $this->step('test.slow', fn (CycleContext $ctx) => StepOutcome::more(['last_id' => 3]));
        $runner = new CycleRunner(steps: ['test.slow' => $slow], plans: ['nightly' => ['test.slow', PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        $runner->work($run->id);
        $this->assertSame('more', CycleRunStep::query()->where('step', 'test.slow')->value('status'));

        $this->at('2026-11-24 06:31:00');
        $runner->work($run->id);
        $run = $run->fresh();
        $this->assertSame('partial', $run->status);
        $step = CycleRunStep::query()->where('step', 'test.slow')->sole();
        $this->assertSame(['skipped', 'deadline'], [$step->status, $step->counts['skipped']]);
        $this->assertStringContainsString('06:30', $step->error);
    }

    public function test_status_lists_the_latest_runs_and_mode_switches_it(): void
    {
        $this->workThrough(app(CycleRunner::class)->start('manual'));
        $this->artisan('ledger:cycle status')->expectsOutputToContain('Mode: on.')->expectsOutputToContain('detect.sync')->assertSuccessful();
        $this->artisan('ledger:cycle mode shadow')->assertSuccessful();
        $this->assertSame('shadow', CycleRunner::mode());
        $event = AuditEvent::query()->where('action', 'settings.intel_cycle')->sole();
        $this->assertSame([['intel_cycle' => 'on'], ['intel_cycle' => 'shadow']], [$event->before, $event->after]);
        $this->artisan('ledger:cycle mode sideways')->assertFailed();
    }
}
