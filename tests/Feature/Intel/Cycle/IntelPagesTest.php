<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Jobs\RunCycleStep;
use App\Models\Account;
use App\Models\CycleRun;
use App\Models\ExchangeRate;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Where you see the cycle (docs/intelligence-cycle.md 7.1 and 7.5, M5): /intel with the latest report and "Check now",
 * the run log at /intel/runs, the card on the dashboard, a run after an import, and the overdue-invoice nudges reading
 * the same invoice.overdue findings as the to-do list.
 */
class IntelPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]), '*' => Http::response([], 404)]);
        $this->at('2026-11-24 09:13:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->withoutVite(); // the built assets may not have these pages yet (CI builds them first)
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

    private function issue(string $title): Invoice
    {
        $job = WorkJob::query()->create(['title' => $title, 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'po_number' => 'PO-'.$title]);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        return app(InvoiceService::class)->issue($invoice->fresh(), '2026-09-15')->fresh();
    }

    public function test_the_overnight_page_shows_the_latest_report_and_runs(): void
    {
        Setting::put('intel_cycle', 'on');
        $this->get('/intel')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('intel/index', false)->where('mode', 'on')->where('report', null)->where('runs', []));

        $run = app(CycleRunner::class)->start('manual');
        for ($i = 0; $i < 10 && ! $run->fresh()->isFinished(); $i++) {
            app(CycleRunner::class)->work($run->id);
        }
        $morning = app(CycleRunner::class)->start('morning');
        $morning->forceFill(['report' => ['at' => now()->toIso8601String(), 'since' => now()->subDay()->toIso8601String(), 'mode' => 'on', 'quiet' => true,
            'line' => 'A quiet night: nothing new found, and nothing needs you.']])->save();

        $this->get('/intel')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('intel/index', false)
            ->where('report.line', 'A quiet night: nothing new found, and nothing needs you.')
            ->where('reportRun.kind', 'morning')
            ->has('runs', 2)
            ->where('runs.1.status', 'ok')
            ->where('runs.1.sealed', true));
        $this->get('/intel/runs')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('intel/runs', false)
            ->has('runs', 2)
            ->where('runs.1.kind_words', 'Check now')
            ->where('runs.1.steps.0.step', 'detect.todo')
            ->where('runs.1.steps.0.words', 'the to-do list’s checks')
            ->where('page.current_page', 1));
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank']); // past the first-run setup
        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('overnight.line', 'A quiet night: nothing new found, and nothing needs you.')
            ->where('overnight.trial', false));
    }

    public function test_check_now_starts_a_run_in_the_worker_and_needs_the_cycle_on(): void
    {
        Bus::fake([RunCycleStep::class]);
        $this->post('/intel/check')->assertSessionHas('error');
        $this->assertSame(0, CycleRun::query()->count());

        Setting::put('intel_cycle', 'shadow');
        $this->post('/intel/check')->assertSessionHas('success');
        $run = CycleRun::query()->sole();
        $this->assertSame(['manual', 'shadow', 'running'], [$run->kind, $run->mode, $run->status]);
        Bus::assertDispatched(RunCycleStep::class, fn (RunCycleStep $j) => $j->runId === $run->id);
        // A second press while it's running gives the same run.
        $this->post('/intel/check')->assertSessionHas('success');
        $this->assertSame(1, CycleRun::query()->count());
        $this->get('/intel')->assertInertia(fn (AssertableInertia $p) => $p->where('checking', true));
    }

    public function test_an_import_queues_a_run_about_what_came_in(): void
    {
        $this->seed();
        Storage::fake('local');
        Setting::put('intel_cycle', 'on');
        Setting::put('own_names', ['Alex Smith']);
        foreach (['EUR' => '1.150000', 'USD' => '1.300000'] as $cur => $rate) {
            ExchangeRate::query()->create(['currency' => $cur, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'units_per_gbp' => $rate, 'source' => 'hmrc_monthly']);
        }
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP', 'last4' => '4821']);
        Bus::fake([RunCycleStep::class]);

        $file = UploadedFile::fake()->createWithContent('revolut.csv', (string) file_get_contents(base_path('tests/fixtures/revolut_personal.csv')));
        $this->post('/imports/preview', ['file' => $file, 'account_id' => $revolut->id])->assertRedirect('/imports');
        $this->post('/imports/commit')->assertSessionHas('success');

        $run = CycleRun::query()->sole();
        $this->assertSame('import', $run->kind);
        $this->assertStringStartsWith('import:batch:', $run->run_key);
        $this->assertSame(['detect.todo', 'detect.sync', 'preserve.seal'], $run->steps()->pluck('step')->all());
        $scope = $run->steps()->first()->scope;
        $this->assertContains('acct:'.$revolut->id, $scope);
        $this->assertNotEmpty(preg_grep('/^batch:\d+$/', $scope));
        Bus::assertDispatched(RunCycleStep::class);
    }

    public function test_an_import_with_the_cycle_off_queues_nothing(): void
    {
        Bus::fake([RunCycleStep::class]);
        $this->assertNull(app(CycleRunner::class)->request('import', 'import:batch:1'));
        $this->assertSame(0, CycleRun::query()->count());
        Bus::assertNotDispatched(RunCycleStep::class);
    }

    public function test_overdue_invoice_nudges_read_the_invoice_overdue_findings(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        $open = $this->issue('Awards');
        $dismissed = $this->issue('Launch');
        $paid = $this->issue('Gala');
        $paid->forceFill(['status' => 'paid'])->save();
        TodoDismissal::query()->create(['key' => 'invoice_overdue:'.$dismissed->id, 'until' => null]);

        $this->artisan('ledger:invoice-nudges')->expectsOutput('Nudged about 1 overdue invoices.')->assertSuccessful();

        // The same invoices as the to-do list's open invoice.overdue findings.
        $findings = Finding::query()->where('code', 'invoice.overdue')->whereNull('resolved_at')->pluck('key')->all();
        $this->assertSame(['invoice_overdue:'.$open->id], $findings);
        $this->assertNotNull($open->fresh()->overdue_nudged_at);
        $this->assertNull($dismissed->fresh()->overdue_nudged_at);
        $this->assertNull($paid->fresh()->overdue_nudged_at);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Invoice '.$open->number.' to Acme Events is 40 days overdue'));

        // Not again within the week, as before.
        $this->artisan('ledger:invoice-nudges')->expectsOutput('Nudged about 0 overdue invoices.')->assertSuccessful();
    }

    public function test_a_snooze_that_has_ended_is_nudged_even_while_the_findings_are_being_recorded(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        $this->at('2026-11-17 09:00:00');
        $ended = $this->issue('Awards');
        $still = $this->issue('Launch');
        FindingsRegistry::sync();
        Dismissals::dismiss('invoice_overdue:'.$ended->id, 7, 'Later'); // until 24 Nov, 09:00
        Dismissals::dismiss('invoice_overdue:'.$still->id, 14, 'Later');
        $this->at('2026-11-24 05:07:00');
        FindingsRegistry::sync(); // the nightly findings: both still snoozed then

        // 09:13: something else is recording the findings right now (an import run, a button on the to-do list).
        $this->at('2026-11-24 09:13:00');
        $lock = Cache::lock('findings-sync', 300);
        $this->assertTrue($lock->get());
        $this->artisan('ledger:invoice-nudges')->expectsOutput('Nudged about 1 overdue invoices.')->assertSuccessful();
        $lock->release();

        $this->assertNotNull($ended->fresh()->overdue_nudged_at, 'Its snooze ended at 09:00: nudged, as the to-do list shows it again');
        $this->assertNull($still->fresh()->overdue_nudged_at, 'Still snoozed');
    }
}
