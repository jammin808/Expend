<?php

namespace Tests\Feature\Income;

use App\Domain\Income\IncomeTargets;
use App\Domain\Income\IncomeText;
use App\Domain\Income\InvoiceMatcher;
use App\Domain\Intel\Provenance;
use App\Domain\Jobs\JobBilling;
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
 * Build slice S3 (income v3's worked-out helpers): the chips a payment is offered (IncomeTargets), whether a job is
 * billed (JobBilling) and what an income suggestion says before you accept it (IncomeText).
 */
class IncomeTargetsTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->acme = Client::query()->create(['name' => 'Acme Events']);
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

    private function job(string $title, string $on = '2026-05-01', ?Client $client = null, string $status = 'done'): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => ($client ?? $this->acme)->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => $status]);
    }

    private function invoice(string $number, int $total, ?WorkJob $job = null, string $issued = '2026-05-15', ?Client $client = null, string $status = 'issued'): Invoice
    {
        $client ??= $this->acme;

        return Invoice::query()->create(['status' => $status, 'number' => $number, 'client_id' => $client->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => $client->name, 'issue_date' => $issued, 'due_date' => '2026-06-14']);
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-28'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('e', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'targets-'.$this->n]);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence] + $links;
    }

    private function decide(BankTransaction $tx, array ...$lines): BankTransaction
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines));

        return $tx;
    }

    private function known(Client $client, string $key = 'ACME PAYROLL', string $on = '2026-03-02'): void
    {
        $this->decide($this->tx(10000, $key, $on), $this->income(10000, ['client_id' => $client->id]));
    }

    /** @return list<array{0:string, 1:string}> each chip's kind and label */
    private function chips(BankTransaction $tx): array
    {
        return array_map(fn (array $c) => [$c['kind'], $c['label']], IncomeTargets::for($tx));
    }

    public function test_money_in_chips_in_order_with_tied_invoices_an_owed_job_and_the_client(): void
    {
        $this->known($this->acme);
        $showA = $this->job('Show A');
        $one = $this->invoice('INV-0042', 45000, $showA);
        $this->invoice('INV-0043', 45000, null, '2026-05-16');
        $this->job('Show B', '2026-05-12');
        $paidJob = $this->job('Show C', '2026-05-20');
        $this->decide($this->tx(30000, 'OTHER PAYER', '2026-05-25'), $this->income(30000, ['work_job_id' => $paidJob->id]));
        $this->job('Show D', '2026-06-05'); // finished, but after the payment

        $tx = $this->tx(45000);
        $this->assertSame([
            ['invoice', 'INV-0042 · Show A · £450'],
            ['invoice', 'INV-0043 · £450'],
            ['job', 'Show B · 12 May · nothing billed'],
            ['client', 'Acme Events · no job'],
        ], $this->chips($tx));

        $chips = IncomeTargets::for($tx);
        $this->assertSame('Save INV-0042', $chips[0]['save']);
        $this->assertSame([$one->id], $chips[0]['invoice_ids']);
        $this->assertSame([['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $showA->id, 'invoice_id' => $one->id,
            'client_id' => null, 'amount_pence' => 45000, 'business_use_pct' => 100, 'note' => null]], $chips[0]['lines']);
        $this->assertSame($this->acme->id, $chips[3]['client_id']);
        $this->assertLessThanOrEqual(IncomeTargets::MAX, count($chips));

        // Saving a chip's lines as your decision gives what it says.
        $this->decide($tx, ...$chips[3]['lines']);
        $this->assertSame([[null, null, $this->acme->id]], Allocation::query()->where('bank_transaction_id', $tx->id)->get()->map(fn ($a) => [$a->work_job_id, $a->invoice_id, $a->client_id])->all());
    }

    public function test_a_set_net_chip_and_no_client_chip_for_an_unknown_payer(): void
    {
        // An unknown payer: only what matches an invoice.
        $this->invoice('INV-0042', 45000);
        $this->assertSame([['invoice', 'INV-0042 · £450']], $this->chips($this->tx(45000, 'NEW PAYER')));
        $this->assertSame([], $this->chips($this->tx(12345, 'NEW PAYER')));

        // A payer with a usual £30 fee: the set net of it.
        foreach ([[100000, '2026-04-10'], [80000, '2026-04-20']] as [$gross, $on]) {
            $this->decide($this->tx($gross - 3000, 'ACME PAYROLL', $on), $this->income($gross, ['client_id' => $this->acme->id]),
                ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000, 'client_id' => $this->acme->id]);
        }
        $this->invoice('INV-0041', 60000, null, '2026-05-01');
        $this->invoice('INV-0043', 75000, null, '2026-05-03');
        $chips = IncomeTargets::for($this->tx(132000));
        $this->assertSame(['net', 'INV-0041 + INV-0043 · £1,350 · £30 taken off'], [$chips[0]['kind'], $chips[0]['label']]);
        $this->assertSame([60000, 75000, -3000], array_column($chips[0]['lines'], 'amount_pence'));
        $this->assertSame(['client', 'Acme Events · no job'], [$chips[1]['kind'], $chips[1]['label']]);
    }

    /**
     * Stage 1 check: a payment is never measured against its own waiting suggestion. Its chips still offer the invoice
     * (or set) it's suggested for, and never swap it for another invoice of the same amount; another payment still
     * can't take the amount that suggestion holds.
     */
    public function test_a_payment_suggested_for_an_invoice_is_still_offered_it(): void
    {
        $showA = $this->job('Show A');
        $this->invoice('INV-0042', 45000, $showA, '2026-05-10');
        $tx = $this->tx(45000, 'NEW PAYER');
        $this->assertTrue(app(InvoiceMatcher::class)->begin([$tx])->write($tx));
        $this->assertSame('suggest.invoice', Allocation::query()->where('bank_transaction_id', $tx->id)->value('method'));

        $this->assertSame([['invoice', 'INV-0042 · Show A · £450']], $this->chips($tx->fresh()));
        $this->assertSame([], $this->chips($this->tx(45000, 'NEW PAYER')), 'another payment can\'t take what the suggestion holds');

        // Another £450 invoice issued later: both are offered to the suggested payment, only the new one to another.
        $this->invoice('INV-0050', 45000, $this->job('Show B'), '2026-05-20');
        $this->assertSame([['invoice', 'INV-0042 · Show A · £450'], ['invoice', 'INV-0050 · Show B · £450']], $this->chips($tx->fresh()));
        $this->assertSame([['invoice', 'INV-0050 · Show B · £450']], $this->chips($this->tx(45000, 'NEW PAYER')));

        // A set with a fee taken off, suggested: still offered.
        foreach ([[100000, '2026-04-10'], [80000, '2026-04-20']] as [$gross, $on]) {
            $this->decide($this->tx($gross - 3000, 'ACME PAYROLL', $on), $this->income($gross, ['client_id' => $this->acme->id]),
                ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000, 'client_id' => $this->acme->id]);
        }
        $this->invoice('INV-0041', 60000, null, '2026-05-01');
        $this->invoice('INV-0043', 75000, null, '2026-05-03');
        $net = $this->tx(132000);
        $this->assertTrue(app(InvoiceMatcher::class)->begin([$net])->write($net));
        $this->assertSame(['net', 'INV-0041 + INV-0043 · £1,350 · £30 taken off'], $this->chips($net->fresh())[0]);
    }

    public function test_a_payer_for_several_clients_gets_invoice_chips_only_its_most_frequent_clients_first(): void
    {
        $other = Client::query()->create(['name' => 'Other Ltd']);
        $this->known($other, 'CREW PAY', '2026-03-01');
        $this->known($other, 'CREW PAY', '2026-03-08');
        $this->known($this->acme, 'CREW PAY', '2026-03-15');
        $this->job('Show B', '2026-05-12');
        $this->invoice('INV-0001', 50000, null, '2026-05-10');
        $this->invoice('INV-0002', 50000, null, '2026-05-11', $other);

        $this->assertSame([['invoice', 'INV-0002 · £500'], ['invoice', 'INV-0001 · £500']], $this->chips($this->tx(50000, 'CREW PAY')));
    }

    public function test_money_out_chips_refund_reversal_and_overpaid(): void
    {
        $this->known($this->acme);
        $show = $this->job('Show A');
        $invoice = $this->invoice('INV-0042', 45000, $show);
        $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-03-03'), $this->income(45000, ['invoice_id' => $invoice->id]));
        $over = $this->invoice('INV-0050', 40000, null, '2026-05-01');
        $this->decide($this->tx(50000, 'ACME PAYROLL', '2026-05-05'), $this->income(50000, ['invoice_id' => $over->id]));

        // £450 back: the payment of 3 Mar was 60+ days before this, so no reversal; a refund to the client.
        $this->assertSame([['refund', 'Refund to Acme Events']], $this->chips($this->tx(-45000, 'ACME PAYROLL', '2026-06-01')));
        $back = $this->tx(-45000, 'ACME PAYROLL', '2026-04-20');
        $chips = IncomeTargets::for($back);
        $this->assertSame(['reversal', 'Money back for the £450 on 3 Mar (INV-0042)'], [$chips[0]['kind'], $chips[0]['label']]);
        $this->assertSame([[-45000, $invoice->id, $show->id]], array_map(fn ($l) => [$l['amount_pence'], $l['invoice_id'], $l['work_job_id']], $chips[0]['lines']));

        // £100 back: INV-0050 was paid £100 more than it was for.
        $chips = IncomeTargets::for($this->tx(-10000, 'ACME PAYROLL', '2026-05-30'));
        $this->assertSame([['overpaid', 'Money back for the £100 overpaid on INV-0050'], ['refund', 'Refund to Acme Events']], array_map(fn ($c) => [$c['kind'], $c['label']], $chips));
        $this->assertSame($over->id, $chips[0]['lines'][0]['invoice_id']);
        $this->decide($refund = $this->tx(-10000, 'ACME PAYROLL', '2026-05-31'), ...$chips[0]['lines']);
        $this->assertSame(-10000, (int) Allocation::query()->where('bank_transaction_id', $refund->id)->value('amount_pence'));
    }

    public function test_job_billing(): void
    {
        $issued = $this->job('Issued');
        $this->invoice('INV-0001', 1000, $issued);
        $legacy = $this->job('Legacy paid');
        $this->invoice('INV-0002', 1000, $legacy, '2026-05-15', null, 'paid');
        $draft = $this->job('Draft only');
        $this->invoice('INV-0003', 1000, $draft, '2026-05-15', null, 'draft');
        $void = $this->job('Void only');
        $this->invoice('INV-0004', 1000, $void, '2026-05-15', null, 'void');
        $paid = $this->job('Paid without an invoice');
        $this->decide($this->tx(20000), $this->income(20000, ['work_job_id' => $paid->id]));
        $covered = $this->job('Covered by another job’s invoice');
        $this->decide($this->tx(20000, 'ACME PAYROLL', '2026-05-29'), $this->income(20000, ['invoice_id' => Invoice::query()->where('number', 'INV-0001')->value('id'), 'work_job_id' => $covered->id]));
        $waiting = $this->job('Income waiting');
        app(AllocationWriter::class)->decide($this->tx(20000, 'ACME PAYROLL', '2026-05-30'), [$this->income(20000, ['work_job_id' => $waiting->id])], 'matcher', false, 'Guess', null, new Provenance('jobs.gather'));
        $refunded = $this->job('Paid and paid back');
        $this->decide($this->tx(20000, 'ACME PAYROLL', '2026-05-30'), $this->income(20000, ['work_job_id' => $refunded->id]));
        $this->decide($this->tx(-20000, 'ACME PAYROLL', '2026-05-31'), $this->income(-20000, ['work_job_id' => $refunded->id]));
        $held = $this->job('Held statement');
        $line = $this->decide($this->tx(20000, 'ACME PAYROLL', '2026-05-30'), $this->income(20000, ['work_job_id' => $held->id]));
        DB::table('bank_transactions')->where('id', $line->id)->update(['state' => 'quarantined']);

        $billed = JobBilling::billed([$issued, $legacy, $draft, $void, $paid, $covered, $waiting, $refunded, $held]);
        $this->assertSame([$issued->id, $legacy->id, $paid->id, $covered->id], array_keys($billed));
        $this->assertTrue(JobBilling::isBilled($paid));
        $this->assertFalse(JobBilling::isBilled($draft->id));
        $this->assertSame([], JobBilling::billed([]));
        $this->assertSame(['Held statement', 'Paid and paid back', 'Income waiting', 'Void only', 'Draft only'], JobBilling::owed($this->acme->id)->pluck('title')->all());
    }

    public function test_client_only_payments_around_a_job(): void
    {
        $show = WorkJob::query()->create(['title' => 'Show A', 'client_id' => $this->acme->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12', 'status' => 'done']);
        $deposit = $this->decide($this->tx(10000, 'ACME PAYROLL', '2026-02-10'), $this->income(10000, ['client_id' => $this->acme->id]));
        $this->decide($this->tx(10000, 'ACME PAYROLL', '2026-02-07'), $this->income(10000, ['client_id' => $this->acme->id])); // 31 days before
        $after = $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-07-10'), $this->income(45000, ['client_id' => $this->acme->id]));
        $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-07-11'), $this->income(45000, ['client_id' => $this->acme->id])); // 121 days after
        $this->decide($this->tx(5000, 'OTHER', '2026-04-01'), $this->income(5000, ['client_id' => Client::query()->create(['name' => 'Other Ltd'])->id]));
        $this->decide($this->tx(5000, 'ACME PAYROLL', '2026-04-02'), $this->income(5000, ['work_job_id' => $show->id])); // on the job already
        app(AllocationWriter::class)->decide($this->tx(5000, 'ACME PAYROLL', '2026-04-03'), [$this->income(5000, ['client_id' => $this->acme->id])], 'matcher', false, 'Guess', null, new Provenance('categorise.hint'));

        $found = JobBilling::clientOnlyAfter($show);
        $this->assertSame([$deposit->id, $after->id], $found->pluck('bank_transaction_id')->all());
        $this->assertTrue($found->first()->relationLoaded('transaction'));
        $this->assertCount(0, JobBilling::clientOnlyAfter(WorkJob::query()->create(['title' => 'No client', 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12', 'status' => 'done'])));
    }

    public function test_income_text_for_exact_set_net_and_plain_suggestions(): void
    {
        $w = app(AllocationWriter::class);
        $showB = $this->job('Show B');
        $a = $this->invoice('INV-0041', 60000);
        $c = $this->invoice('INV-0043', 75000);

        $set = $this->tx(132000);
        $w->decide($set, [$this->income(60000, ['invoice_id' => $a->id]), $this->income(75000, ['invoice_id' => $c->id]),
            ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000, 'client_id' => $this->acme->id]],
            'matcher', false, 'Net', null, new Provenance('suggest.invoice_net'));
        $lines = Allocation::query()->where('bank_transaction_id', $set->id)->get();
        $this->assertSame('INV-0041 £600 + INV-0043 £750, £30 fee taken off · Acme Events', IncomeText::suggestion($lines));
        $this->assertTrue(IncomeText::isInvoiceSuggestion($lines));

        $exact = $this->tx(60000);
        $w->decide($exact, [$this->income(60000, ['invoice_id' => $a->id])], 'matcher', false, 'Exact', null, new Provenance('suggest.invoice'));
        $this->assertSame('INV-0041 £600 · Acme Events', IncomeText::suggestion(Allocation::query()->where('bank_transaction_id', $exact->id)->get()));

        $job = $this->tx(45000);
        $w->decide($job, [$this->income(45000, ['work_job_id' => $showB->id])], 'matcher', false, 'Job', null, new Provenance('jobs.gather'));
        $jobLines = Allocation::query()->where('bank_transaction_id', $job->id)->get();
        $this->assertSame('Turnover · Show B · Acme Events', IncomeText::suggestion($jobLines));
        $this->assertFalse(IncomeText::isInvoiceSuggestion($jobLines));

        $client = $this->tx(45000);
        $w->decide($client, [$this->income(45000, ['client_id' => $this->acme->id])], 'matcher', false, 'Client', null, new Provenance('categorise.hint'));
        $this->assertSame('Turnover · Acme Events', IncomeText::suggestion(Allocation::query()->where('bank_transaction_id', $client->id)->get()));

        // Anything else reads as it always did.
        $plain = $this->tx(45000);
        $w->decide($plain, [$this->income(45000)], 'matcher', false, 'Plain', null, new Provenance('categorise.hint'));
        $this->assertSame('Business income: Turnover (sales and fees)', IncomeText::suggestion(Allocation::query()->where('bank_transaction_id', $plain->id)->get()));
        $cost = $this->tx(-1250, 'TFL');
        $w->decide($cost, [['kind' => 'business_expense', 'category_id' => $this->cat('travel'), 'amount_pence' => -1250, 'work_job_id' => $showB->id]], 'matcher', false, 'TfL', null, new Provenance('suggest.travel'));
        $this->assertSame('Business expense: '.Category::query()->where('code', 'travel')->value('name').' · Show B', IncomeText::suggestion(Allocation::query()->where('bank_transaction_id', $cost->id)->get()));

        // Confirmed lines are no suggestion.
        app(AllocationWriter::class)->confirm($exact);
        $this->assertFalse(IncomeText::isInvoiceSuggestion(Allocation::query()->where('bank_transaction_id', $exact->id)->get()));

        // The year page's group label.
        $group = Allocation::query()->whereIn('bank_transaction_id', [$set->id, $job->id, $client->id])->get();
        $this->assertSame('Business income · Acme Events · 1 invoice match', IncomeText::group($group));
        $this->assertNull(IncomeText::group(Allocation::query()->whereIn('bank_transaction_id', [$set->id, $cost->id])->get()));

        // Payslip-like words, and short amounts.
        $this->assertTrue(IncomeText::isPayslipLike('ACME PAYE'));
        $this->assertTrue(IncomeText::isPayslipLike('Salary', 'NATIONAL INSURANCE'));
        $this->assertFalse(IncomeText::isPayslipLike('ACME PAYROLL', 'TAXI CO'));
        $this->assertSame(['£1,350', '£0.50', '€1,200'], [IncomeText::money(135000), IncomeText::money(50), IncomeText::money(120000, 'EUR')]);
    }
}
