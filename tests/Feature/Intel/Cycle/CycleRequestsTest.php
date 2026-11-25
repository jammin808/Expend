<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Steps\DetectSync;
use App\Domain\Intel\Cycle\Steps\PreserveSeal;
use App\Domain\Intel\Cycle\Steps\VerifyBacklog;
use App\Domain\Invoices\InvoiceService;
use App\Jobs\RunCycleStep;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs asked for, and steps kept to their time (docs/intelligence-cycle.md 6.1 to 6.3, M5): an import or "Check now"
 * while a run that will record the findings is going joins it rather than starting another; detect.sync never sits in
 * the worker waiting for the findings, and never takes "someone else is recording" for a sync that found nothing; and
 * the double-check of AI findings works in turns that fit a step job.
 */
class CycleRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('job_finder_auto', false);
        Setting::put('intel_cycle', 'on');
        Http::fake();
        $this->at('2026-11-24 14:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $london): void
    {
        $utc = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    private function workThrough(CycleRun $run, int $max = 60): CycleRun
    {
        for ($i = 0; $i < $max && ! $run->fresh()->isFinished(); $i++) {
            app(CycleRunner::class)->work($run->id);
        }

        return $run->fresh();
    }

    private function overdueInvoice(): Invoice
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'po_number' => 'PO-1']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        $issued = app(InvoiceService::class)->issue($invoice->fresh(), '2026-09-15')->fresh();
        // Sent (S10: an invoice not sent is listed to send, never as overdue).
        $issued->forceFill(['sent_at' => '2026-09-15 10:00:00', 'sent_to' => 'accounts@acme.example'])->save();

        return $issued->fresh();
    }

    public function test_statements_uploaded_one_after_another_make_one_run(): void
    {
        Bus::fake([RunCycleStep::class]);
        $runner = app(CycleRunner::class);
        $a = $runner->request('import', 'import:batch:1:140000', ['batch:1', 'acct:1']);
        $b = $runner->request('import', 'import:batch:2:140010', ['batch:2', 'acct:2']);
        $c = $runner->request('manual');

        $this->assertNotNull($a);
        $this->assertSame([$a->id, $a->id], [$b->id, $c->id], 'The run still to record the findings covers them');
        $this->assertSame(1, CycleRun::query()->count());
        Bus::assertDispatchedTimes(RunCycleStep::class, 1);
        foreach (CycleRunStep::query()->where('cycle_run_id', $a->id)->get() as $step) {
            $this->assertSame(['batch:1', 'acct:1', 'batch:2', 'acct:2'], $step->scope, $step->step.' is about both statements');
        }

        // Once it has recorded the findings, the next statement gets a run of its own.
        $this->workThrough($a);
        $d = $runner->request('import', 'import:batch:3:141500', ['batch:3', 'acct:1']);
        $this->assertNotSame($a->id, $d->id);
        $this->assertSame(2, CycleRun::query()->count());
    }

    public function test_check_now_during_the_nightly_run_gives_that_run_and_a_double_press_makes_one(): void
    {
        Bus::fake([RunCycleStep::class]);
        $this->at('2026-11-24 03:05:00');
        $this->artisan('ledger:cycle tick');
        $night = CycleRun::query()->sole();

        $this->post('/intel/check')->assertSessionHas('success', 'The nightly check is running now: it records what it finds when it gets there.');
        $this->assertSame(1, CycleRun::query()->count(), 'No second run beside the nightly one');

        // Past its detect.sync, "Check now" starts one; pressed twice, still one.
        CycleRunStep::query()->where('cycle_run_id', $night->id)->where('step', DetectSync::KEY)->update(['status' => 'ok']);
        $this->post('/intel/check')->assertSessionHas('success', 'Checking now: the findings are updated within a minute or two.');
        $this->post('/intel/check')->assertSessionHas('success');
        $this->assertSame(1, CycleRun::query()->where('kind', 'manual')->count());
        $this->assertSame(2, CycleRun::query()->where('status', 'running')->count());
    }

    public function test_a_trial_run_doesnt_cover_a_request_once_the_cycle_is_on(): void
    {
        Bus::fake([RunCycleStep::class]);
        $runner = app(CycleRunner::class);
        $trial = $runner->start('manual', mode: 'shadow');
        $run = $runner->request('manual');
        $this->assertNotSame($trial->id, $run->id);
        $this->assertSame('on', $run->mode, 'A trial records nothing, so it can’t stand in for a real check');
    }

    public function test_detect_sync_doesnt_wait_in_the_worker_for_the_findings_and_never_calls_that_a_sync(): void
    {
        Bus::fake([RunCycleStep::class]);
        $invoice = $this->overdueInvoice();
        // Something else is recording the findings (a button on the to-do list, the history page, ledger:findings).
        $other = Cache::lock(DetectSync::LOCK, 300);
        $this->assertTrue($other->get());

        $runner = app(CycleRunner::class);
        $run = $runner->start('manual');
        $started = microtime(true);
        for ($i = 0; $i < 10; $i++) {
            $runner->work($run->id);
        }
        $this->assertLessThan(5, microtime(true) - $started, 'Not 20 seconds blocked in the worker');
        $sync = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', DetectSync::KEY)->sole();
        $this->assertSame(['more', null, 1], [$sync->status, $sync->coverage, $sync->cursor['waits']], 'Tried again in a minute, and not called complete');
        $this->assertFalse($run->fresh()->isFinished());
        $this->assertSame(0, Finding::query()->count());

        $other->release();
        $this->at('2026-11-24 14:02:00');
        $run = $this->workThrough($run);
        $sync = $sync->fresh();
        $this->assertSame(['ok', 'complete'], [$sync->status, $sync->coverage]);
        $this->assertSame('ok', $run->status);
        $this->assertTrue(Finding::query()->where('key', 'invoice_overdue:'.$invoice->id)->exists());
    }

    public function test_detect_sync_fails_after_waiting_ten_minutes(): void
    {
        Bus::fake([RunCycleStep::class]);
        $this->assertTrue(Cache::lock(DetectSync::LOCK, 3600)->get());
        $runner = new CycleRunner(plans: ['manual' => [DetectSync::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('manual');
        for ($minute = 0; $minute <= DetectSync::WAITS && ! $run->fresh()->isFinished(); $minute++) {
            $this->at('2026-11-24 14:'.sprintf('%02d', $minute).':30');
            $runner->work($run->id);
        }
        $sync = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', DetectSync::KEY)->sole();
        $this->assertSame('pending', $sync->status, 'Failed once: tried again as any failing step is');
        $this->assertStringContainsString('being recorded by something else for 10 minutes', $sync->error);
    }

    public function test_the_double_check_of_ai_findings_works_in_turns_that_fit_a_step_job(): void
    {
        foreach (range(1, 30) as $i) {
            Document::query()->create(['source' => 'upload', 'original_name' => "po{$i}.pdf", 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_pad((string) $i, 64, 'a'),
                'stored_path' => "documents/po{$i}.pdf", 'status' => 'extracted', 'text_status' => 'extracted']);
        }
        $runner = new CycleRunner(plans: ['nightly' => [VerifyBacklog::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly', deadline: CarbonImmutable::now()->addYear());
        $step = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', VerifyBacklog::KEY)->sole();

        // The clock moves on 100 seconds every time it's read: the job's time is up after its first turn.
        $clock = CarbonImmutable::now();
        $tick = function () use (&$clock) {
            $clock = $clock->addSeconds(100);

            return $clock;
        };
        Carbon::setTestNow(fn () => Carbon::instance($tick()));
        CarbonImmutable::setTestNow(fn () => $tick());

        $runner->work($run->id);
        $this->assertSame(['more', 1, 5], [$step()->status, $step()->cursor['turns'], $step()->counts['checked']], 'One turn of five of each kind, then the next job');

        $this->workThrough($run);
        $this->assertSame(['ok', VerifyBacklog::TURNS, 25], [$step()->status, $step()->counts['turns'], $step()->counts['checked']], 'At most five turns a night; the rest tomorrow');
        $this->assertSame(1, $step()->attempts, 'Carrying on isn’t another try');
    }

    public function test_a_step_job_is_stopped_well_within_the_workers_time(): void
    {
        $job = new RunCycleStep(1);
        $this->assertLessThanOrEqual(150, $job->timeout, 'With --max-time=240, the worker ends within about 400 seconds even when a step overruns');
        $this->assertGreaterThan(CycleRunner::NIGHT_SECONDS, $job->timeout);
    }
}
