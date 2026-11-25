<?php

namespace Tests\Feature\Income;

use App\Domain\Import\RevolutImport;
use App\Domain\Income\InvoiceMatcher;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Build slice S3 (income v3's matching engine): InvoiceMatcher proposes which invoice money in is for, one level per
 * pass (exact, else a set of the payer's client's invoices, else with a fee or commission taken off), on what's still
 * to come; refuses ties, other clients' invoices for a known payer, vetoed invoices and amounts already taken; never
 * writes over your lines, a rule, a veto or any invoice suggestion, and never throws doing so.
 */
class InvoiceMatcherTest extends TestCase
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

    private function matcher(iterable $txs = []): InvoiceMatcher
    {
        return app(InvoiceMatcher::class)->begin($txs);
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function job(string $title, ?Client $client = null): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => ($client ?? $this->acme)->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
    }

    private function invoice(string $number, int $total, ?WorkJob $job = null, string $issued = '2026-05-15', ?Client $client = null, string $currency = 'GBP'): Invoice
    {
        $client ??= $this->acme;

        return Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $client->id, 'work_job_id' => $job?->id, 'currency' => $currency,
            'total_minor' => $total, 'bill_to_name' => $client->name, 'issue_date' => $issued, 'due_date' => CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d')]);
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-28', array $extra = [], ?Account $account = null): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $account ??= $this->account;
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('d', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'matcher-'.$this->n] + $extra);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence] + $links;
    }

    /** A payment from the payer that you decided as the client's, so the payer is known for it. */
    private function known(Client $client, string $key = 'ACME PAYROLL', string $on = '2026-03-02'): BankTransaction
    {
        $tx = $this->tx(10000, $key, $on);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income(10000, ['client_id' => $client->id])]));

        return $tx;
    }

    /** A receipt you decided with a fee taken off, so the payer's usual fee is known. */
    private function receiptWithFee(int $gross, int $fee, string $on, string $key = 'ACME PAYROLL', string $category = 'other'): BankTransaction
    {
        $tx = $this->tx($gross - $fee, $key, $on);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [
            $this->income($gross, ['client_id' => $this->acme->id]),
            ['kind' => 'business_expense', 'category_id' => $this->cat($category), 'amount_pence' => -$fee, 'client_id' => $this->acme->id],
        ]));

        return $tx;
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:bool}> kind, job, invoice, client, amount, confirmed */
    private function stored(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->invoice_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null])->all();
    }

    private function guess(BankTransaction $tx): void
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income($tx->amount_pence)], 'matcher', false, 'Looks like income', null, new Provenance('categorise.hint')));
    }

    public function test_a_second_instalment_matches_what_is_still_to_come(): void
    {
        $show = $this->job('Show A');
        $invoice = $this->invoice('INV-0042', 100000, $show);
        $first = $this->tx(40000, 'ACME PAYROLL', '2026-05-20');
        app(AllocationWriter::class)->decide($first, [$this->income(40000, ['invoice_id' => $invoice->id])]);

        $second = $this->tx(60000);
        $p = $this->matcher([$second])->propose($second);
        $this->assertSame(['suggest.invoice', 'exact', 'Matches what’s still to come on INV-0042 (£600)'], [$p['method'], $p['level'], $p['reason']]);

        $this->assertTrue($this->matcher([$second])->write($second));
        $this->assertSame([['business_income', $show->id, $invoice->id, null, 60000, false]], $this->stored($second), 'a suggestion, never confirmed');
        $a = Allocation::query()->where('bank_transaction_id', $second->id)->sole();
        $this->assertSame(['suggest.invoice', 2, 1], [$a->method, $a->method_version, $a->score]);
        $this->assertSame(['inv:'.$invoice->id, 'job:'.$show->id], $a->evidence);
    }

    public function test_a_tie_is_refused_and_a_known_payer_breaks_it(): void
    {
        $other = Client::query()->create(['name' => 'Other Ltd']);
        $mine = $this->invoice('INV-0001', 50000);
        $theirs = $this->invoice('INV-0002', 50000, null, '2026-05-15', $other);
        $tx = $this->tx(50000);
        $o = $this->matcher([$tx])->options($tx);
        $this->assertCount(2, $o['exact']);
        $this->assertNull($this->matcher([$tx])->propose($tx), 'two invoices of that amount: not guessed');

        $this->known($this->acme);
        $p = $this->matcher([$tx])->propose($tx);
        $this->assertSame($mine->id, $p['lines'][0]['invoice_id']);
        $this->assertSame(2, $p['provenance']->score);
        $this->assertSame([['ref' => 'inv:'.$theirs->id, 'score' => null]], $p['provenance']->alternatives);
    }

    public function test_a_payer_known_from_two_payments_is_never_matched_to_another_clients_invoice(): void
    {
        $other = Client::query()->create(['name' => 'Other Ltd']);
        $theirs = $this->invoice('INV-0002', 70000, null, '2026-05-15', $other);
        $tx = $this->tx(70000);

        $this->known($this->acme);
        $this->assertSame($theirs->id, $this->matcher([$tx])->propose($tx)['lines'][0]['invoice_id'], 'one known payment doesn’t filter');

        $this->known($this->acme, 'ACME PAYROLL', '2026-03-09');
        $this->assertNull($this->matcher([$tx])->propose($tx), 'two do: only Acme Events’ invoices');

        // A client-less invoice whose bill-to name is the client's still counts as theirs.
        Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0003', 'currency' => 'GBP', 'total_minor' => 70000, 'bill_to_name' => 'Acme Events Ltd', 'issue_date' => '2026-05-15']);
        $this->assertSame('INV-0003', Invoice::query()->find($this->matcher([$tx])->propose($tx)['lines'][0]['invoice_id'])->number);
    }

    public function test_two_payments_in_one_run_never_take_the_same_amount(): void
    {
        $invoice = $this->invoice('INV-0010', 50000);
        $a = $this->tx(50000, 'ACME PAYROLL', '2026-05-27');
        $b = $this->tx(50000);
        $run = $this->matcher([$a, $b]);

        $this->assertTrue($run->write($a));
        $this->assertFalse($run->write($b));
        $this->assertSame([], $this->stored($b));
        $this->assertSame($invoice->id, $this->stored($a)[0][2]);
    }

    public function test_two_500_invoices_on_one_job_with_one_payment_waiting(): void
    {
        // One payment waiting for INV-0001 takes only INV-0001's amount, not the job's: the next £500 is INV-0002's.
        $show = $this->job('Show A');
        $one = $this->invoice('INV-0001', 50000, $show, '2026-05-10');
        $two = $this->invoice('INV-0002', 50000, $show, '2026-05-12');
        $waiting = $this->tx(50000, 'ACME PAYROLL', '2026-05-20');
        app(AllocationWriter::class)->decide($waiting, [$this->income(50000, ['invoice_id' => $one->id])], 'matcher', false, 'Matches', null, new Provenance('suggest.invoice', [], 1));

        $next = $this->tx(50000);
        $p = $this->matcher([$next])->propose($next);
        $this->assertSame($two->id, $p['lines'][0]['invoice_id']);
    }

    public function test_a_foreign_invoice_matches_the_original_amount_unless_a_payment_waits_that_cant_be_measured(): void
    {
        $euros = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR']);
        $invoice = $this->invoice('INV-0020', 120000, null, '2026-05-15', null, 'EUR');
        $tx = $this->tx(102000, 'ACME PAYROLL', '2026-05-28', ['original_currency' => 'EUR', 'original_amount_minor' => 120000], $euros);
        $this->assertSame($invoice->id, $this->matcher([$tx])->propose($tx)['lines'][0]['invoice_id']);
        $this->assertNull($this->matcher([$tx])->options($tx)['net'], 'no fee shortcut for a foreign invoice');

        // A pound payment waiting that names it can't be measured in euros: the invoice is left out.
        $pounds = $this->tx(30000, 'ACME PAYROLL', '2026-05-26');
        app(AllocationWriter::class)->decide($pounds, [$this->income(30000, ['invoice_id' => $invoice->id])], 'matcher', false, 'Matches', null, new Provenance('suggest.invoice', [], 1));
        $this->assertNull($this->matcher([$tx])->propose($tx));
    }

    public function test_a_deposit_before_the_invoice_only_from_its_known_payer(): void
    {
        $invoice = $this->invoice('INV-0030', 40000, null, '2026-05-20');
        $early = $this->tx(40000, 'ACME PAYROLL', '2026-05-01');
        $this->assertNull($this->matcher([$early])->propose($early), '19 days early from an unknown payer');
        $slack = $this->tx(40000, 'SOMEONE', '2026-05-18');
        $this->assertSame($invoice->id, $this->matcher([$slack])->propose($slack)['lines'][0]['invoice_id'], 'within 3 days');

        $this->known($this->acme);
        $this->assertSame($invoice->id, $this->matcher([$early])->propose($early)['lines'][0]['invoice_id'], 'a deposit from Acme’s payer');
    }

    public function test_a_unique_set_of_the_clients_invoices_is_proposed_and_two_sets_are_not(): void
    {
        $this->known($this->acme);
        $a = $this->invoice('INV-0041', 60000, $this->job('Show A'), '2026-05-01');
        $this->invoice('INV-0042', 30000, null, '2026-05-02');
        $c = $this->invoice('INV-0043', 75000, $this->job('Show B'), '2026-05-03');
        $tx = $this->tx(135000);

        $p = $this->matcher([$tx])->propose($tx);
        $this->assertSame(['suggest.invoice_set', 'INV-0041 + INV-0043 add up to £1,350'], [$p['method'], $p['reason']]);

        // Another combination (INV-0042 + INV-0044) makes it two: nothing.
        $d = $this->invoice('INV-0044', 105000, null, '2026-05-04');
        $this->assertNull($this->matcher([$tx])->propose($tx));
        $this->assertNull($this->matcher([$tx])->options($tx)['net']);

        $d->update(['status' => 'void', 'void_reason' => 'Sent twice']);
        $this->assertTrue($this->matcher([$tx])->write($tx));
        $this->assertSame([
            ['business_income', $a->job->id, $a->id, null, 60000, false],
            ['business_income', $c->job->id, $c->id, null, 75000, false],
        ], $this->stored($tx));
    }

    public function test_only_the_clients_oldest_12_invoices_are_searched_and_a_vetoed_invoice_goes_first(): void
    {
        $this->known($this->acme);
        foreach (range(1, 12) as $i) {
            $this->invoice(sprintf('INV-%04d', $i), 1000 * $i + 7, null, sprintf('2026-05-%02d', $i));
        }
        $newest = $this->invoice('INV-0013', 50000, null, '2026-05-20');
        $tx = $this->tx(50000 + 1007);
        $this->assertNull($this->matcher([$tx])->propose($tx), 'INV-0001 + INV-0013 is the only fit, but INV-0013 isn’t searched');

        // Two sets fit; saying the payment isn't for one invoice leaves a unique set without it.
        $a = $this->invoice('INV-0101', 20011, null, '2026-04-01');
        $b = $this->invoice('INV-0102', 30013, null, '2026-04-02');
        $c = $this->invoice('INV-0103', 30013, null, '2026-04-03');
        $pay2 = $this->tx(50024, 'ACME PAYROLL', '2026-05-30');
        $this->assertNull($this->matcher([$pay2])->propose($pay2), 'INV-0101 + INV-0102 and INV-0101 + INV-0103');
        Vetoes::record('invoice_payment', 'tx:'.$pay2->id, 'inv:'.$c->id, null, 'suggest.invoice');
        $p = $this->matcher([$pay2])->propose($pay2);
        $this->assertSame([$a->id, $b->id], array_column($p['lines'], 'invoice_id'));
        $this->assertNotContains('inv:'.$newest->id, $p['provenance']->evidence);
    }

    public function test_net_of_the_banks_pound_fee(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $show = $this->job('Show A');
        $invoice = $this->invoice('INV-0042', 100000, $show);
        $tx = $this->tx(99950, 'ACME PAYROLL', '2026-05-28', ['fee_minor' => -50], $revolut);

        $p = $this->matcher([$tx])->propose($tx);
        $this->assertSame(['suggest.invoice_net', '£1,000 for INV-0042 with the bank’s £0.50 fee taken off'], [$p['method'], $p['reason']]);
        $this->assertTrue($this->matcher([$tx])->write($tx));
        $this->assertSame([['business_income', $show->id, $invoice->id, null, 100000, false], ['business_expense', $show->id, null, null, -50, false]], $this->stored($tx));
        $this->assertSame($this->cat('finance'), Allocation::query()->where('bank_transaction_id', $tx->id)->where('kind', 'business_expense')->value('category_id'));

        // The bank's fee only for a pound account, as the statement gave it; none otherwise.
        $euros = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR']);
        $this->assertSame(50, RevolutImport::poundFee($tx));
        $this->assertNull(RevolutImport::poundFee($this->tx(99950, 'ACME PAYROLL', '2026-05-28', [], $revolut)));
        $this->assertNull(RevolutImport::poundFee($this->tx(99950, 'ACME PAYROLL', '2026-05-28', ['fee_minor' => -50, 'original_currency' => 'EUR', 'original_amount_minor' => 117000], $euros)));
    }

    public function test_net_of_the_payers_usual_fixed_fee_or_rate(): void
    {
        $this->receiptWithFee(100000, 3000, '2026-04-10');
        $this->receiptWithFee(80000, 3000, '2026-04-20');
        $invoice = $this->invoice('INV-0050', 120000);
        $tx = $this->tx(117000);
        $p = $this->matcher([$tx])->propose($tx);
        $this->assertSame('£1,200 for INV-0050 with £30 taken off as a fee, like the last 2 payments from ACME PAYROLL', $p['reason']);
        $this->assertSame([120000, -3000], array_column($p['lines'], 'amount_pence'));
        $this->assertSame([$invoice->id, null], array_column($p['lines'], 'invoice_id'));
        $this->assertSame([$this->cat('other'), $this->acme->id], [$p['lines'][1]['category_id'], $p['lines'][1]['client_id']], 'the learned category; the client, with no job');

        // A rate: 1.5% of each.
        $agent = Client::query()->create(['name' => 'Crew Agency']);
        foreach ([[100000, 1500, '2026-04-11'], [200000, 3000, '2026-04-21']] as [$gross, $fee, $on]) {
            $r = $this->tx($gross - $fee, 'CREW AGENCY', $on);
            app(AllocationWriter::class)->decide($r, [$this->income($gross, ['client_id' => $agent->id]),
                ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -$fee, 'client_id' => $agent->id]]);
        }
        $this->invoice('INV-0060', 50000, null, '2026-05-15', $agent);
        $rated = $this->tx(49250, 'CREW AGENCY');
        $p = $this->matcher([$rated])->propose($rated);
        $this->assertSame([50000, -750], array_column($p['lines'], 'amount_pence'));
    }

    public function test_set_net_and_nothing_net_for_foreign_money_or_a_payslip_like_payer(): void
    {
        $this->receiptWithFee(100000, 3000, '2026-04-10');
        $this->receiptWithFee(80000, 3000, '2026-04-20');
        $a = $this->invoice('INV-0041', 60000, null, '2026-05-01');
        $c = $this->invoice('INV-0043', 75000, null, '2026-05-03');
        $tx = $this->tx(132000);
        $p = $this->matcher([$tx])->propose($tx);
        $this->assertSame('£1,350 for INV-0041 + INV-0043 with £30 taken off as a fee, like the last 2 payments from ACME PAYROLL', $p['reason']);
        $this->assertSame([[$a->id, 60000], [$c->id, 75000], [null, -3000]], array_map(fn ($l) => [$l['invoice_id'], $l['amount_pence']], $p['lines']));

        // Into a euro account: no fee shortcut.
        $euros = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR']);
        $foreign = $this->tx(132000, 'ACME PAYROLL', '2026-05-28', ['original_currency' => 'EUR', 'original_amount_minor' => 155000], $euros);
        $this->assertNull($this->matcher([$foreign])->propose($foreign));

        // A payer whose words look like a payslip: never a fee.
        Client::query()->create(['name' => 'Big Payroll']);
        $this->receiptWithFee(100000, 3000, '2026-04-12', 'ACME PAYE');
        $this->receiptWithFee(80000, 3000, '2026-04-22', 'ACME PAYE');
        $paye = $this->tx(132000, 'ACME PAYE');
        $this->assertNull($this->matcher([$paye])->propose($paye));
    }

    public function test_it_never_writes_over_an_invoice_suggestion_your_lines_a_rule_or_a_veto_and_never_throws(): void
    {
        $one = $this->invoice('INV-0001', 50000);
        $two = $this->invoice('INV-0002', 50000);
        $w = app(AllocationWriter::class);

        // An invoice suggestion, whatever made it (the assistant's naming INV-0001), is never replaced.
        $assistant = $this->tx(50000);
        $w->decide($assistant, [$this->income(50000, ['invoice_id' => $one->id])], 'ai', false, 'You said it was INV-0001', null, new Provenance('ai.assistant'));
        $this->assertFalse($this->matcher([$assistant])->write($assistant));
        $this->assertSame($one->id, $this->stored($assistant)[0][2]);

        // A guess is replaced by the match (the other invoice: INV-0001 is taken by the waiting one).
        $guessed = $this->tx(50000, 'ACME PAYROLL', '2026-05-29');
        $this->guess($guessed);
        $this->assertTrue($this->matcher([$guessed])->write($guessed));
        $this->assertSame($two->id, $this->stored($guessed)[0][2]);

        $three = $this->invoice('INV-0003', 45000);
        // Your own lines; a rule's suggestion (it ranks above an invoice match); a veto; a locked year: refused, never thrown.
        $mine = $this->tx(45000);
        $w->decide($mine, [$this->income(45000)]);
        $this->assertFalse($this->matcher([$mine])->write($mine));
        $ruled = $this->tx(45000, 'ACME PAYROLL', '2026-05-30');
        $w->decide($ruled, [$this->income(45000)], 'rule', false, 'Your rule for ACME PAYROLL', null, new Provenance('rule.owner'));
        $this->assertFalse($this->matcher([$ruled])->write($ruled));
        $this->assertSame('rule', Allocation::query()->where('bank_transaction_id', $ruled->id)->value('decided_by'));
        $vetoed = $this->tx(45000, 'ACME PAYROLL', '2026-05-31');
        Vetoes::record('invoice_payment', 'tx:'.$vetoed->id, 'inv:'.$three->id);
        $this->assertFalse($this->matcher([$vetoed])->write($vetoed));
        $old = $this->tx(45000, 'ACME PAYROLL', '2025-05-20');
        $this->invoice('INV-0004', 45000, null, '2025-05-10');
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();
        $this->assertFalse($this->matcher([$old])->write($old));
        $this->assertSame([], $this->stored($old));
    }

    public function test_your_own_money_and_money_out_are_never_matched(): void
    {
        $this->invoice('INV-0001', 50000);
        $own = $this->tx(50000, 'ALEX SMITH');
        $this->assertNull($this->matcher([$own])->propose($own));
        $out = $this->tx(-50000);
        $this->assertNull($this->matcher([$out])->propose($out));
    }

    public function test_for_invoice_suggests_it_for_waiting_payments_but_never_over_a_rule_yours_a_confirmed_line_or_an_invoice_suggestion(): void
    {
        $show = $this->job('Show A');
        $w = app(AllocationWriter::class);
        $earlier = $this->invoice('INV-0001', 60000, null, '2026-05-01');
        $guessed = $this->tx(60000, 'ACME PAYROLL', '2026-05-20');
        $this->guess($guessed);
        $ruled = $this->tx(60000, 'ACME PAYROLL', '2026-05-21');
        $w->decide($ruled, [$this->income(60000)], 'rule', false, 'Your rule for ACME PAYROLL', null, new Provenance('rule.owner'));
        $mine = $this->tx(60000, 'ACME PAYROLL', '2026-05-22');
        $w->decide($mine, [$this->income(60000)]);
        // The assistant's proposal naming two invoices, for a payment of the new invoice's amount.
        $third = $this->invoice('INV-0003', 30000, null, '2026-05-02');
        $assistant = $this->tx(60000, 'ACME PAYROLL', '2026-05-23');
        $w->decide($assistant, [$this->income(30000, ['invoice_id' => $earlier->id]), $this->income(30000, ['invoice_id' => $third->id])], 'ai', false, 'You said', null, new Provenance('ai.assistant'));

        $new = Invoice::query()->create(['status' => 'draft', 'client_id' => $this->acme->id, 'work_job_id' => $show->id, 'currency' => 'GBP', 'total_minor' => 60000,
            'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-18']);
        $this->assertSame(0, app(InvoiceMatcher::class)->forInvoice($new), 'a draft: nothing');
        $new->update(['status' => 'issued', 'number' => 'INV-0002']);

        $this->assertSame(1, app(InvoiceMatcher::class)->forInvoice($new));
        $this->assertSame([['business_income', $show->id, $new->id, null, 60000, false]], $this->stored($guessed));
        $this->assertSame('rule', Allocation::query()->where('bank_transaction_id', $ruled->id)->value('decided_by'));
        $this->assertSame([['business_income', null, null, null, 60000, true]], $this->stored($mine));
        $this->assertSame([$earlier->id, $third->id], array_column($this->stored($assistant), 2));
        $this->assertSame('ai', Allocation::query()->where('bank_transaction_id', $assistant->id)->value('decided_by'));
    }

    public function test_for_invoice_never_fails_whatever_goes_wrong(): void
    {
        $invoice = $this->invoice('INV-0009', 30000);
        $this->tx(30000);
        Allocation::creating(fn () => throw new RuntimeException('Disk full'));

        $this->assertSame(0, app(InvoiceMatcher::class)->forInvoice($invoice));
    }
}
