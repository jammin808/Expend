<?php

namespace Tests\Feature\Intel;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\Steps\RelateJobs;
use App\Domain\Intel\Cycle\Steps\RelateSweep;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\Suggester;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * relate.sweep (M7): automatic suggestions whose basis has gone are taken back by matching, with the reason: a voided or
 * paid invoice's match, a cancelled job's cost, a deleted rule's decision, and what you've since said no to. Your
 * decisions, anything confirmed and locked years are never touched.
 */
class RelateSweepTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2025-07-01 03:10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(string $date, int $pence, string $description): BankTransaction
    {
        $bank = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'merchant_key' => MerchantKey::from($description, null), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function sweep(?CycleContext $ctx = null): array
    {
        return app(RelateSweep::class)->run($ctx ?? new CycleContext)->counts;
    }

    private function invoice(int $total, string $status = 'issued'): Invoice
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);

        return Invoice::query()->forceCreate(['client_id' => $client->id, 'number' => 'INV-'.(++$this->n), 'status' => $status, 'currency' => 'GBP', 'total_minor' => $total,
            'issue_date' => '2025-06-01', 'due_date' => '2025-07-01']);
    }

    public function test_a_voided_invoices_match_is_swept(): void
    {
        $invoice = $this->invoice(45000);
        $paid = $this->tx('2025-06-20', 45000, 'ACME EVENTS LTD');
        app(Suggester::class)->suggestFor(collect([$paid]));
        $this->assertSame($invoice->id, Allocation::query()->where('bank_transaction_id', $paid->id)->value('invoice_id'));

        $invoice->forceFill(['status' => 'void'])->saveQuietly();
        $counts = $this->sweep();

        $this->assertSame(1, $counts['void_invoice']);
        $this->assertSame([0, 'unreviewed'], [Allocation::query()->where('bank_transaction_id', $paid->id)->count(), $paid->fresh()->review_status]);
        $cleared = AuditEvent::query()->where('action', 'allocation.cleared')->sole();
        $this->assertSame('matcher', $cleared->actor);
        $this->assertStringContainsString('has been voided', (string) $cleared->reason);
        // Run twice: nothing more to take back.
        $this->assertSame(0, $this->sweep()['swept']);
    }

    public function test_a_match_to_an_invoice_paid_by_other_payments_is_swept(): void
    {
        $invoice = $this->invoice(45000);
        $first = $this->tx('2025-06-20', 45000, 'ACME EVENTS LTD');
        $second = $this->tx('2025-06-21', 45000, 'ACME EVENTS LTD');
        $writer = app(AllocationWriter::class);
        $lines = AllocationWriter::lines(45000, 'business_income', $this->cat('turnover'));
        $lines[0]['invoice_id'] = $invoice->id;
        $writer->decide($first, $lines, 'you');
        $writer->decide($second, $lines, 'matcher', false, 'Matches invoice '.$invoice->number, null, new Provenance('suggest.invoice', ['inv:'.$invoice->id]));

        $this->assertSame(1, $this->sweep()['paid_invoice']);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $second->id)->count());
        $this->assertSame(1, Allocation::query()->where('bank_transaction_id', $first->id)->count(), 'Your decision stays');
    }

    public function test_a_cancelled_jobs_cost_and_a_deleted_rules_decision_are_swept(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'status' => 'booked']);
        $hire = $this->tx('2025-06-10', -3000, 'KIT HIRE LTD');
        $writer = app(AllocationWriter::class);
        $writer->decide($hire, AllocationWriter::lines(-3000, 'business_expense', $this->cat('equipment'), $job->id), 'matcher', false, 'A business cost on Arena load-in’s dates', null,
            new Provenance('jobs.gather', ['job:'.$job->id]));
        $rule = MerchantRule::query()->create(['merchant_key' => 'ADOBE', 'kind' => 'business_expense', 'category_id' => $this->cat('software'), 'business_pct' => 100, 'auto_confirm' => false]);
        $adobe = $this->tx('2025-06-11', -1200, 'ADOBE');
        $writer->decide($adobe, AllocationWriter::lines(-1200, 'business_expense', $this->cat('software')), 'rule', false, 'Your rule for ADOBE', $rule->id, new Provenance('rule.owner'));
        // Confirmed by its rule: never swept.
        $kept = $this->tx('2025-06-12', -1200, 'ADOBE');
        $writer->decide($kept, AllocationWriter::lines(-1200, 'business_expense', $this->cat('software')), 'rule', true, 'Your rule for ADOBE', $rule->id, new Provenance('rule.owner'));

        $this->assertSame(0, $this->sweep()['swept'], 'Nothing has gone yet');
        $job->update(['status' => 'cancelled']);
        $rule->delete();
        $counts = $this->sweep();

        $this->assertSame([1, 1], [$counts['cancelled_job'], $counts['deleted_rule']]);
        $this->assertSame([0, 0, 1], [Allocation::query()->where('bank_transaction_id', $hire->id)->count(), Allocation::query()->where('bank_transaction_id', $adobe->id)->count(),
            Allocation::query()->where('bank_transaction_id', $kept->id)->count()]);
        $this->assertStringContainsString('Arena load-in was cancelled', (string) AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $hire->id)->value('reason'));
    }

    public function test_what_you_have_since_said_no_to_is_swept_but_never_a_locked_year(): void
    {
        $writer = app(AllocationWriter::class);
        $pret = $this->tx('2025-06-12', -650, 'PRET A MANGER');
        $old = $this->tx('2024-06-12', -650, 'PRET A MANGER');
        foreach ([$pret, $old] as $t) {
            $writer->decide($t, AllocationWriter::lines(-650, 'personal'), 'matcher', false, 'Looks like food or drink', null, new Provenance('categorise.hint'));
            Vetoes::record('allocation_shape', 'tx:'.$t->id, null, Vetoes::shape([['kind' => 'personal']]));
        }
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);

        $this->assertSame(1, $this->sweep()['vetoed']);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $pret->id)->count());
        $this->assertSame(1, Allocation::query()->where('bank_transaction_id', $old->id)->count(), 'A locked year never changes');
    }

    public function test_it_carries_on_from_its_cursor_when_its_time_is_up(): void
    {
        $invoice = $this->invoice(45000);
        $txs = collect(range(1, 3))->map(fn ($i) => $this->tx('2025-06-2'.$i, 45000, 'ACME EVENTS LTD'));
        $lines = AllocationWriter::lines(45000, 'business_income', $this->cat('turnover'));
        $lines[0]['invoice_id'] = $invoice->id;
        foreach ($txs as $t) {
            app(AllocationWriter::class)->decide($t, $lines, 'matcher', false, 'Matches invoice', null, new Provenance('suggest.invoice'));
        }
        $invoice->forceFill(['status' => 'void'])->saveQuietly();

        $step = new RelateSweep(app(AllocationWriter::class), 2);
        $out = $step->run(new CycleContext(deadline: now()->subSecond()));
        $this->assertSame('more', $out->status);
        $this->assertSame(['part' => 'void_invoice', 'last_id' => $txs[1]->id], array_intersect_key($out->cursor, array_flip(['part', 'last_id'])));
        $this->assertSame(1, Allocation::query()->whereIn('bank_transaction_id', $txs->pluck('id'))->count());

        $done = $step->run(new CycleContext(cursor: $out->cursor));
        $this->assertSame('ok', $done->status);
        $this->assertSame(3, $done->counts['void_invoice']);
        $this->assertSame(0, Allocation::query()->whereIn('bank_transaction_id', $txs->pluck('id'))->count());
    }

    public function test_it_is_a_step_of_the_nightly_run_that_writes(): void
    {
        $plan = CycleRunner::PLANS['nightly'];
        $this->assertSame(array_search(RelateJobs::KEY, $plan, true) + 1, array_search(RelateSweep::KEY, $plan, true), 'After relate.jobs');
        $this->assertSame(RelateSweep::class, CycleRunner::STEPS[RelateSweep::KEY]);
        $step = app(RelateSweep::class);
        $this->assertSame(['relate.sweep', Stage::Relate, true], [$step->key(), $step->stage(), $step->writes()]);
        $this->assertSame('taking back suggestions that no longer hold', CycleRunner::stepWords(RelateSweep::KEY));
    }
}
