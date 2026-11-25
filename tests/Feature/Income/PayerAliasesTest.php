<?php

namespace Tests\Feature\Income;

use App\Domain\Income\PayerAliases;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Build slice S3 (income v3's worked-out helpers): PayerAliases works out who pays for whom from what you decided.
 * It counts your decisions, suggestions you accepted whose client comes from their invoice or job, and older matcher
 * or AI lines confirmed before who confirmed was kept. It never counts a client automation stored on a line that you
 * merely accepted, rule-confirmed lines, waiting suggestions, money paid back, voided lines, held statements or
 * "UNKNOWN" payers. A payer seen for two clients names neither.
 */
class PayerAliasesTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function client(string $name): Client
    {
        return Client::query()->create(['name' => $name]);
    }

    private function job(string $title, ?Client $client): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $client?->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
    }

    private function invoice(string $number, int $total, ?Client $client, ?WorkJob $job = null): Invoice
    {
        return Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $client?->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => $client?->name ?? 'Someone', 'issue_date' => '2026-05-02', 'due_date' => '2026-06-01']);
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-20'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'aliases-'.$this->n]);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'), 'amount_pence' => $pence] + $links;
    }

    /** A payment from the payer that you decided yourself, with these links. */
    private function yours(array $links, string $key = 'ACME PAYROLL', int $pence = 50000): BankTransaction
    {
        $tx = $this->tx($pence, $key);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income($pence, $links)]));

        return $tx;
    }

    /** A matcher's suggestion with these links, left open. */
    private function suggested(array $links, string $key = 'ACME PAYROLL', int $pence = 50000): BankTransaction
    {
        $tx = $this->tx($pence, $key);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income($pence, $links)], 'matcher', false, 'Matches invoice', null, new Provenance('suggest.invoice', [], 1)));

        return $tx;
    }

    public function test_it_counts_your_decisions_and_suggestions_you_accepted_whose_client_comes_from_their_invoice_or_job(): void
    {
        $acme = $this->client('Acme Events');
        $show = $this->job('Show A', $acme);
        $invoice = $this->invoice('INV-0001', 50000, $acme);

        $this->yours(['client_id' => $acme->id]); // yours, client stored on the line: counts
        $this->yours(['work_job_id' => $show->id]); // yours, from its job
        $accepted = $this->suggested(['invoice_id' => $invoice->id]); // accepted: its client comes from the invoice
        app(AllocationWriter::class)->confirm($accepted);

        $aliases = PayerAliases::forKeys(['ACME PAYROLL']);
        $this->assertSame([$acme->id => ['n' => 3, 'latest' => $accepted->id]], $aliases->clients('ACME PAYROLL'));
        $this->assertSame($acme->id, $aliases->sole('ACME PAYROLL', 3));
        $this->assertNull($aliases->sole('ACME PAYROLL', 4));
        $this->assertSame(['ACME PAYROLL' => 3], PayerAliases::keysFor($acme->id));
    }

    public function test_legacy_matcher_and_ai_lines_confirmed_before_who_confirmed_was_kept_count(): void
    {
        $acme = $this->client('Acme Events');
        $show = $this->job('Show A', $acme);
        $old = $this->suggested(['work_job_id' => $show->id]);
        Allocation::query()->where('bank_transaction_id', $old->id)->update(['confirmed_at' => now(), 'confirmed_by' => null]);
        $ai = $this->tx(30000);
        DB::table('allocations')->insert(['bank_transaction_id' => $ai->id, 'tax_year' => '2026-27', 'kind' => 'business_income', 'amount_pence' => 30000,
            'category_id' => Category::query()->where('code', 'turnover')->value('id'), 'work_job_id' => $show->id, 'decided_by' => 'ai', 'is_manual' => false,
            'confirmed_at' => now(), 'confirmed_by' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(2, PayerAliases::forKeys(['ACME PAYROLL'])->count('ACME PAYROLL', $acme->id));
    }

    public function test_what_it_never_counts(): void
    {
        $acme = $this->client('Acme Events');
        $show = $this->job('Show A', $acme);

        // A client automation stored on the line (enrichment) that you only accepted teaches nothing new.
        $enriched = $this->suggested(['client_id' => $acme->id]);
        app(AllocationWriter::class)->confirm($enriched);
        // A rule's line confirmed by the rule itself (an older line: the writer no longer confirms linked income).
        $ruled = $this->suggested(['work_job_id' => $show->id]);
        Allocation::query()->where('bank_transaction_id', $ruled->id)->update(['decided_by' => 'rule', 'confirmed_at' => now(), 'confirmed_by' => 'rule']);
        // A suggestion still waiting.
        $this->suggested(['work_job_id' => $show->id]);
        // An "UNKNOWN" payer.
        $this->yours(['work_job_id' => $show->id], 'UNKNOWN');
        // Money paid back to them.
        $refund = $this->tx(-20000);
        app(AllocationWriter::class)->decide($refund, [$this->income(-20000, ['client_id' => $acme->id])]);
        // A voided line, and a held statement's line.
        $voided = $this->yours(['work_job_id' => $show->id]);
        Allocation::query()->where('bank_transaction_id', $voided->id)->update(['voided_at' => now()]);
        $held = $this->yours(['work_job_id' => $show->id]);
        DB::table('bank_transactions')->where('id', $held->id)->update(['state' => 'quarantined']);
        // Income with no client at all.
        $this->yours([]);

        $this->assertSame([], PayerAliases::forKeys(['ACME PAYROLL', 'UNKNOWN'])->clients('ACME PAYROLL'));
        $this->assertSame([], PayerAliases::forKeys(['UNKNOWN'])->clients('UNKNOWN'));
        $this->assertNull(PayerAliases::soleClient('ACME PAYROLL'));
        $this->assertSame([], PayerAliases::keysFor($acme->id));
    }

    public function test_the_worked_out_client_is_the_invoices_then_the_jobs_then_the_lines(): void
    {
        $agency = $this->client('Crew Agency');
        $venue = $this->client('Big Venue');
        $other = $this->client('Other Ltd');
        $show = $this->job('Show A', $venue);
        $invoice = $this->invoice('INV-0001', 50000, $agency, $show);

        $this->yours(['invoice_id' => $invoice->id, 'work_job_id' => $show->id], 'PAYER A'); // the invoice's client, not the job's
        $this->yours(['work_job_id' => $show->id, 'client_id' => $other->id], 'PAYER B'); // the job's client (the stored one is dropped)
        $loose = $this->job('Show B', null);
        $this->yours(['work_job_id' => $loose->id, 'client_id' => $other->id], 'PAYER C'); // the job names none: the line's own

        $aliases = PayerAliases::forKeys(['PAYER A', 'PAYER B', 'PAYER C']);
        $this->assertSame($agency->id, $aliases->sole('PAYER A'));
        $this->assertSame($venue->id, $aliases->sole('PAYER B'));
        $this->assertSame($other->id, $aliases->sole('PAYER C'));
    }

    public function test_a_payer_for_two_clients_names_neither_and_one_client_needs_enough_payments(): void
    {
        $x = $this->client('Acme Events');
        $y = $this->client('Other Ltd');
        foreach (range(1, 3) as $i) {
            $this->yours(['client_id' => $x->id]);
        }
        $this->yours(['client_id' => $y->id]);

        $aliases = PayerAliases::forKeys(['ACME PAYROLL']);
        $this->assertNull($aliases->sole('ACME PAYROLL', 1), 'shared always wins');
        $this->assertNull($aliases->sole('ACME PAYROLL', 2));
        $this->assertTrue($aliases->shared('ACME PAYROLL'));
        $this->assertSame([$x->id, $y->id], array_keys($aliases->clients('ACME PAYROLL')), 'most payments first');

        // The 2-payment threshold, for a payer of one client.
        $this->yours(['client_id' => $x->id], 'ACME EVENTS LTD');
        $this->assertSame($x->id, PayerAliases::soleClient('ACME EVENTS LTD', 1));
        $this->assertNull(PayerAliases::soleClient('ACME EVENTS LTD', 2));
        $this->yours(['client_id' => $x->id], 'ACME EVENTS LTD');
        $this->assertSame($x->id, PayerAliases::soleClient('ACME EVENTS LTD', 2));
        $this->assertSame(['ACME PAYROLL' => 3, 'ACME EVENTS LTD' => 2], PayerAliases::keysFor($x->id));
    }

    public function test_a_merged_client_counts_as_the_one_it_went_into(): void
    {
        $acme = $this->client('Acme Events');
        $dupe = $this->client('ACME EVENTS LTD');
        $this->yours(['client_id' => $acme->id]);
        $this->yours(['client_id' => $dupe->id]);
        $this->assertNull(PayerAliases::soleClient('ACME PAYROLL'));

        $dupe->forceFill(['merged_into_id' => $acme->id])->save();
        $this->assertSame($acme->id, PayerAliases::soleClient('ACME PAYROLL', 2));
        $this->assertSame(['ACME PAYROLL' => 2], PayerAliases::keysFor($dupe->id));
    }

    public function test_enrich_fills_in_the_payers_sole_client_on_unlinked_income_only(): void
    {
        $acme = $this->client('Acme Events');
        $latest = $this->yours(['client_id' => $acme->id]);
        $tx = $this->tx(45000);
        $turnover = (int) Category::query()->where('code', 'turnover')->value('id');

        $got = PayerAliases::enrich($tx, AllocationWriter::lines(45000, 'business_income', $turnover));
        $this->assertSame($acme->id, $got['lines'][0]['client_id']);
        $this->assertSame(' · for Acme Events (from your payments)', $got['note']);
        $this->assertSame(['tx:'.$latest->id], $got['evidence']);

        // A line on a job, or a payer for two clients: nothing changes.
        $show = $this->job('Show A', null);
        $onJob = AllocationWriter::lines(45000, 'business_income', $turnover, $show->id);
        $this->assertSame($onJob, PayerAliases::enrich($tx, $onJob)['lines']);
        $this->yours(['client_id' => $this->client('Other Ltd')->id]);
        $this->assertNull(PayerAliases::enrich($tx, AllocationWriter::lines(45000, 'business_income', $turnover))['note']);
    }
}
