<?php

namespace Tests\Feature\Income;

use App\Domain\Income\InvoiceMatcher;
use App\Domain\Intel\Provenance;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Suggester;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobFind;
use App\Models\Setting;
use App\Models\User;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S8 (income design v3, JobGatherer): a line written again keeps its own client; "Not this job" on a
 * payment matched to the job's invoice takes the invoice off too (and remembers it when the match was automatic);
 * "Link" puts only turnover that isn't for anything yet, or is for the job's own client, on the job and refuses another
 * client's; and the job's possible fees include payments from payers known to pay for its client, earliest first.
 */
class JobGathererIncomeTest extends TestCase
{
    use RefreshDatabase;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    private Client $acme;

    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->acme = Client::query()->create(['name' => 'Acme Events']);
        $this->other = Client::query()->create(['name' => 'Other Ltd']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-28'): BankTransaction
    {
        if (! $this->batch) {
            $account = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
            $this->batch = ImportBatch::query()->create(['account_id' => $account->id, 'original_filename' => 'b.csv', 'file_sha256' => str_repeat('f', 64), 'stored_path' => 'x', 'status' => 'committed']);
        }
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->batch->account_id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'gatherer-'.$this->n]);
    }

    private function job(string $title = 'Arena load-in', ?Client $client = null, array $extra = []): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => ($client ?? $this->acme)->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-10', 'status' => 'done'] + $extra);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence] + $links;
    }

    private function find(WorkJob $job, BankTransaction $tx, string $type = 'income'): JobFind
    {
        return JobFind::query()->create(['work_job_id' => $job->id, 'type' => $type, 'ref' => (string) $tx->id, 'status' => 'possible', 'label' => 'x', 'detail' => 'x']);
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:bool}> kind, job, invoice, client, amount, confirmed */
    private function stored(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->invoice_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null])->all();
    }

    public function test_not_this_job_keeps_each_lines_own_client(): void
    {
        $job = $this->job();
        $pay = $this->tx(50000);
        // You split it: £300 for the job, £200 for Other Ltd with no job.
        app(AllocationWriter::class)->decide($pay, [$this->income(30000, ['work_job_id' => $job->id]), $this->income(20000, ['client_id' => $this->other->id])]);

        app(JobGatherer::class)->reject($this->find($job, $pay));

        $this->assertSame([['business_income', null, null, null, 30000, true], ['business_income', null, null, $this->other->id, 20000, true]], $this->stored($pay));
    }

    public function test_not_this_job_takes_the_jobs_invoice_off_an_automatic_match_and_remembers_it(): void
    {
        $job = $this->job();
        $invoice = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0042', 'client_id' => $this->acme->id, 'work_job_id' => $job->id, 'currency' => 'GBP',
            'total_minor' => 45000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-15', 'due_date' => '2026-06-14']);
        $pay = $this->tx(45000);
        app(AllocationWriter::class)->decide($pay, [$this->income(45000, ['invoice_id' => $invoice->id])], 'matcher', false,
            'Matches what’s still to come on INV-0042 (£450)', null, new Provenance('suggest.invoice', ['inv:'.$invoice->id, 'job:'.$job->id]));
        $this->assertSame($job->id, Allocation::query()->where('bank_transaction_id', $pay->id)->value('work_job_id'));

        app(JobGatherer::class)->reject($this->find($job, $pay));

        $this->assertSame([['business_income', null, null, null, 45000, false]], $this->stored($pay), 'Off the job and off its invoice, still a suggestion');
        $line = Allocation::query()->where('bank_transaction_id', $pay->id)->sole();
        $this->assertSame(['matcher', 'jobs.gather'], [$line->decided_by, $line->method]);
        $this->assertNotContains('inv:'.$invoice->id, $line->evidence);
        $veto = Veto::query()->where('relation', 'invoice_payment')->sole();
        $this->assertSame(['tx:'.$pay->id, 'inv:'.$invoice->id, 'not_this_job', null], [$veto->subject_ref, $veto->object_ref, $veto->reason_code, $veto->method]);

        // Your own invoice-linked payment: both come off, as you said, and nothing is remembered against it.
        $yours = $this->tx(45000, 'ACME PAYROLL', '2026-05-29');
        app(AllocationWriter::class)->decide($yours, [$this->income(45000, ['invoice_id' => $invoice->id])]);
        app(JobGatherer::class)->reject($this->find($job, $yours, 'payment'));
        $this->assertSame([['business_income', null, null, null, 45000, true]], $this->stored($yours));
        $this->assertSame(1, Veto::query()->where('relation', 'invoice_payment')->count());
    }

    public function test_link_moves_only_unassigned_or_same_client_turnover_and_refuses_another_clients(): void
    {
        $job = $this->job();
        $writer = app(AllocationWriter::class);

        // Undecided: the whole payment is the job's turnover.
        $plain = $this->tx(40000);
        app(JobGatherer::class)->accept($this->find($job, $plain));
        $this->assertSame([['business_income', $job->id, null, null, 40000, true]], $this->stored($plain));

        // Acme's, with a fee taken off: both go on the job (the job says whose it is).
        $acmes = $this->tx(97000);
        $writer->decide($acmes, [$this->income(100000, ['client_id' => $this->acme->id]),
            ['kind' => 'business_expense', 'category_id' => $this->cat('finance'), 'amount_pence' => -3000, 'client_id' => $this->acme->id]]);
        app(JobGatherer::class)->accept($this->find($job, $acmes));
        $this->assertSame([['business_income', $job->id, null, null, 100000, true], ['business_expense', $job->id, null, null, -3000, true]], $this->stored($acmes));

        // Another client's: refused, and left as it is.
        $others = $this->tx(25000, 'OTHER PAY');
        $writer->decide($others, [$this->income(25000, ['client_id' => $this->other->id])]);
        $find = $this->find($job, $others);
        try {
            app(JobGatherer::class)->accept($find);
            $this->fail('Another client’s payment isn’t moved');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('That payment is for Other Ltd: open it to change it.', $e->getMessage());
        }
        $this->assertSame([['business_income', null, null, $this->other->id, 25000, true]], $this->stored($others));
        $this->assertNull($find->fresh(), 'The out-of-date find goes');

        // A line naming a job-less invoice stays as it is beside the one that moves.
        $invoice = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0050', 'client_id' => $this->acme->id, 'currency' => 'GBP',
            'total_minor' => 30000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-15', 'due_date' => '2026-06-14']);
        $mixed = $this->tx(50000);
        $writer->decide($mixed, [$this->income(30000, ['invoice_id' => $invoice->id]), $this->income(20000)]);
        app(JobGatherer::class)->accept($this->find($job, $mixed));
        $this->assertSame([['business_income', null, $invoice->id, null, 30000, true], ['business_income', $job->id, null, null, 20000, true]], $this->stored($mixed));
    }

    public function test_the_jobs_possible_fees_include_a_known_payer_earliest_first(): void
    {
        // PAYROLL SERVICES has paid for Acme before (no word of Acme's name in it).
        $known = $this->tx(10000, 'PAYROLL SERVICES', '2026-03-01');
        app(AllocationWriter::class)->decide($known, [$this->income(10000, ['client_id' => $this->acme->id])]);
        $job = $this->job('Spring show', null, ['starts_on' => '2026-04-20', 'ends_on' => '2026-04-20']);
        // 35 payments from it after the job, newest made first: the earliest are offered, not the last 30 made.
        $made = [];
        for ($d = 35; $d >= 1; $d--) {
            $made[$d] = $this->tx(20000 + $d, 'PAYROLL SERVICES', CarbonImmutable::parse('2026-04-20')->addDays($d)->format('Y-m-d'));
        }
        // Another client's payment, though it names Acme, isn't offered. (From PAYROLL SERVICES it would make that payer
        // shared, and a shared payer's payments aren't offered at all.)
        $others = $this->tx(30000, 'ACME HOLDINGS', '2026-04-21');
        app(AllocationWriter::class)->decide($others, [$this->income(30000, ['client_id' => $this->other->id])]);

        app(JobGatherer::class)->gather($job, false);

        $offered = JobFind::query()->where('work_job_id', $job->id)->where('type', 'income')->pluck('ref')->map(fn ($r) => (int) $r)->sort()->values()->all();
        $this->assertContains($made[1]->id, $offered, 'The earliest');
        $this->assertNotContains($made[35]->id, $offered, 'Past the 30 looked at');
        $this->assertNotContains($others->id, $offered);
        $this->assertStringContainsString('it’s from Acme Events', (string) JobFind::query()->where('ref', (string) $made[1]->id)->value('detail'));
    }

    public function test_a_payer_that_also_pays_for_another_client_neither_speaks_for_the_client_nor_crowds_out_the_agreed_fee(): void
    {
        // CREW AGENCY pays you for Acme and for Other Ltd: it says nothing about whose a payment is.
        app(AllocationWriter::class)->decide($this->tx(10000, 'CREW AGENCY', '2026-02-01'), [$this->income(10000, ['client_id' => $this->acme->id])]);
        app(AllocationWriter::class)->decide($this->tx(12000, 'CREW AGENCY', '2026-02-08'), [$this->income(12000, ['client_id' => $this->other->id])]);
        $job = $this->job('Spring show', null, ['starts_on' => '2026-04-20', 'ends_on' => '2026-04-20', 'fee_pence' => 65000]);
        for ($d = 1; $d <= 31; $d++) {
            $this->tx(20000 + $d, 'CREW AGENCY', CarbonImmutable::parse('2026-04-20')->addDays($d)->format('Y-m-d'));
        }
        // The agreed fee comes later, from a payer that names nobody; and a payer that pays for Acme alone.
        $fee = $this->tx(65000, 'FASTER PAYMENT 88812', '2026-05-30');
        app(AllocationWriter::class)->decide($this->tx(9000, 'PAYROLL SERVICES', '2026-03-01'), [$this->income(9000, ['client_id' => $this->acme->id])]);
        $sole = $this->tx(30000, 'PAYROLL SERVICES', '2026-06-01');

        app(JobGatherer::class)->gather($job, false);

        $finds = JobFind::query()->where('work_job_id', $job->id)->where('type', 'income')->get()->keyBy('ref');
        $this->assertTrue($finds->has((string) $fee->id), 'The payment of the agreed fee is offered');
        $this->assertSame('Looks like payment for this job: it’s the agreed fee (£650.00)', $finds[(string) $fee->id]->detail);
        $this->assertFalse($finds->contains(fn (JobFind $f) => str_contains($f->label, 'CREW AGENCY')), 'A shared payer’s payments aren’t offered as the fee');
        $this->assertSame('Looks like payment for this job: it’s from Acme Events', $finds[(string) $sole->id]->detail ?? null, 'A payer that pays for Acme alone still is');
    }

    public function test_not_this_job_holds_when_the_jobs_invoice_is_replaced_or_a_later_invoice_is_issued(): void
    {
        $job = $this->job();
        $invoice = fn (string $number, string $issued) => Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $this->acme->id,
            'work_job_id' => $job->id, 'currency' => 'GBP', 'total_minor' => 45000, 'bill_to_name' => 'Acme Events', 'issue_date' => $issued, 'due_date' => '2026-06-14']);
        $first = $invoice('INV-0042', '2026-05-15');
        $pay = $this->tx(45000, 'SOMEONE ELSE');
        app(Suggester::class)->suggestFor(collect([$pay]));
        $this->assertSame($first->id, Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'), 'Matched automatically');

        app(JobGatherer::class)->reject($this->find($job, $pay)); // you: "Not this job"
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $pay->id)->value('work_job_id'));

        // INV-0042 had a typo: voided and replaced by INV-0043 for the same job and amount, which is then issued.
        $first->update(['status' => 'void']);
        $second = $invoice('INV-0043', '2026-05-16');
        $first->update(['replaced_by_id' => $second->id]);
        $this->assertSame(0, app(InvoiceMatcher::class)->forInvoice($second));
        $this->assertSame([[null, null]], Allocation::query()->where('bank_transaction_id', $pay->id)->get()->map(fn (Allocation $a) => [$a->work_job_id, $a->invoice_id])->all(),
            'Not put back on the job you said it isn’t for');
        app(Suggester::class)->suggestFor(collect([$pay->fresh()]));
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'), 'Nor by the next import’s matching');

        // A job-wide suggestion (no invoice) taken off: the job's first invoice issued later isn't matched to it either.
        $later = $this->job('Autumn show', null, ['starts_on' => '2026-05-20', 'ends_on' => '2026-05-20']);
        $guess = $this->tx(38000, 'ANOTHER PAYER', '2026-06-01');
        app(AllocationWriter::class)->decide($guess, [$this->income(38000, ['work_job_id' => $later->id])], 'matcher', false, 'Looks like the fee', null,
            new Provenance('jobs.gather', ['job:'.$later->id]));
        app(JobGatherer::class)->reject($this->find($later, $guess));
        $its = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0044', 'client_id' => $this->acme->id, 'work_job_id' => $later->id, 'currency' => 'GBP',
            'total_minor' => 38000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-25', 'due_date' => '2026-06-24']);
        app(InvoiceMatcher::class)->forInvoice($its);
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $guess->id)->value('work_job_id'));

        // Another job's invoice for the same amount is still matched: only the job you said no to is left out.
        $elsewhere = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0045', 'client_id' => $this->acme->id,
            'work_job_id' => $this->job('Summer show')->id, 'currency' => 'GBP', 'total_minor' => 45000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-20', 'due_date' => '2026-06-19']);
        $this->assertSame(1, app(InvoiceMatcher::class)->forInvoice($elsewhere));
        $this->assertSame($elsewhere->id, Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'));
    }
}
