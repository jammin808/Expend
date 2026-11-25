<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Invoices\InvoiceService;
use App\Jobs\ReadDocument;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The trial week (docs/intelligence-cycle.md 6.1, M5 rollout): with intel_cycle = shadow, a night and a morning run
 * write nothing outside the cycle_* tables (watched with DB::listen; work done inside a transaction that is rolled back
 * isn't written), send nothing, queue nothing, and say in their counts what they would have changed.
 */
class CycleShadowTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** @var list<list<string>> the tables written to, by open transaction (the first: none open since listening) */
    private array $writes = [[]];

    protected function setUp(): void
    {
        parent::setUp();
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('job_finder_auto', false);
        Setting::put('intel_cycle', 'shadow');
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->at('2026-11-24 03:10:00');
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

    private function tx(string $date, int $pence, string $description, string $account = 'Starling'): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => $account], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => $account.'.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'counterparty' => null, 'merchant_key' => strtoupper($description), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    /** Watch every write from now on, keeping only those not undone by a rollback. */
    private function watchWrites(): void
    {
        Event::listen(TransactionBeginning::class, function () {
            $this->writes[] = [];
        });
        Event::listen(TransactionCommitted::class, function () {
            $top = array_pop($this->writes);
            $this->writes[count($this->writes) - 1] = [...$this->writes[count($this->writes) - 1], ...$top];
        });
        Event::listen(TransactionRolledBack::class, function () {
            array_pop($this->writes);
            if ($this->writes === []) {
                $this->writes = [[]];
            }
        });
        DB::listen(function (QueryExecuted $q) {
            if (preg_match('/^\s*(?:insert|update|delete|replace)\s+(?:or\s+\w+\s+)?(?:into\s+|from\s+)?[`"]?(\w+)/i', $q->sql, $m)) {
                $this->writes[count($this->writes) - 1][] = $m[1];
            }
        });
    }

    /** @return list<string> the tables written to, and kept */
    private function written(): array
    {
        return array_values(array_unique(array_merge(...$this->writes)));
    }

    public function test_a_trial_night_and_morning_write_nothing_outside_the_run_log(): void
    {
        // Work the old schedule would do: a transfer to pair, a payment to categorise, an overdue invoice to record,
        // and a document waiting for AI budget.
        $this->tx('2026-11-20', -12000, 'TO SAVINGS', 'Starling');
        $this->tx('2026-11-20', 12000, 'FROM CURRENT', 'Savings');
        $this->tx('2026-11-21', -850, 'TRAINLINE');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'po_number' => 'PO-1']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);
        app(InvoiceService::class)->issue($invoice->fresh(), '2026-09-15');
        $doc = Document::query()->create(['original_name' => 'po.pdf', 'stored_path' => 'documents/po.pdf', 'mime' => 'application/pdf', 'sha256' => str_repeat('b', 64),
            'size' => 10, 'source' => 'upload', 'status' => ReadDocument::WAITING, 'error' => 'This month’s AI spending cap has no room left for reading documents']);
        $auditBefore = AuditEvent::query()->count();
        $settingsBefore = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();
        Bus::fake();

        $this->watchWrites();
        $runner = app(CycleRunner::class);
        $night = $runner->start('nightly');
        for ($i = 0; $i < 60 && ! $night->fresh()->isFinished(); $i++) {
            $runner->work($night->id);
        }
        $this->at('2026-11-24 07:31:00');
        $morning = $runner->start('morning');
        for ($i = 0; $i < 60 && ! $morning->fresh()->isFinished(); $i++) {
            $runner->work($morning->id);
        }

        $this->assertContains('cycle_run_steps', $this->written(), 'Writes are seen');
        $this->assertSame([], array_values(array_diff($this->written(), ['cycle_runs', 'cycle_run_steps'])), 'Only the run log is written');
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame($auditBefore, AuditEvent::query()->count(), 'No audit event, not even a seal');
        $this->assertSame($settingsBefore, DB::table('settings')->orderBy('key')->pluck('value', 'key')->all());
        $this->assertSame(ReadDocument::WAITING, $doc->fresh()->status, 'Not queued again in a trial');
        $this->assertNull(BankTransaction::query()->whereNotNull('transfer_pair_id')->first(), 'Not paired in a trial');
        Http::assertNothingSent();
        Bus::assertNothingDispatched();

        // The run log says what was skipped and what would have changed.
        $night = $night->fresh();
        $this->assertSame(['shadow', 'ok'], [$night->mode, $night->status]);
        $this->assertNull($night->seal_audit_id);
        $this->assertSame(CycleRunner::digest(), $night->digest);
        $steps = CycleRunStep::query()->where('cycle_run_id', $night->id)->get()->keyBy('step');
        foreach (['acquire.retry_reads', 'relate.transfers', 'relate.categorise', 'relate.jobs', 'verify.backlog'] as $writer) {
            $this->assertSame(['skipped', 'shadow'], [$steps[$writer]->status, $steps[$writer]->counts['skipped']], $writer);
        }
        $this->assertSame('ok', $steps['verify.audit_chain']->status);
        $this->assertGreaterThanOrEqual(1, $steps['detect.sync']->counts['new'], 'What it would have opened');
        $this->assertTrue($steps['detect.sync']->counts['shadow']);
        $report = $morning->fresh()->report;
        $this->assertIsArray($report, 'The report is kept with the run, for /intel');
        $this->assertNull($morning->fresh()->reported_at, 'Not sent in a trial');
        $this->assertSame('ok', CycleRunStep::query()->where('cycle_run_id', $morning->id)->where('step', 'recommend.alerts')->value('status'));
    }
}
