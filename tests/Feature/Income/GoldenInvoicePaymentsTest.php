<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Golden pin (build slice S0, income v3 and the client-area design): what InvoicePayments::received() gives today for a
 * ledger with today's shapes only, pasted from running HEAD 2d54ad6. Every later slice keeps this green: income v3 adds
 * keys to each invoice's array (state, closed, waiting_linked…), but paid, elsewhere and waiting stay exactly these for
 * today's data, so only those three are compared.
 *
 * The invoices are written as rows, as an existing ledger holds them (issued before the new release), and every payment
 * is decided through AllocationWriter as the owner or as a matcher's open suggestion, never by automation confirming a
 * link. A line naming an invoice carries the invoice's own job, and links are made while the invoice is still issued
 * (a later void doesn't move them), so normalising links (income v3) changes nothing here.
 */
class GoldenInvoicePaymentsTest extends TestCase
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

    private function writer(): AllocationWriter
    {
        return app(AllocationWriter::class);
    }

    private function turnover(): int
    {
        return (int) Category::query()->where('code', 'turnover')->value('id');
    }

    private function job(string $title, string $on = '2026-05-01'): WorkJob
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);

        return WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => 'done']);
    }

    /** An invoice as an existing ledger holds it: issued (or a draft) before this release, with its total. */
    private function invoice(WorkJob $job, ?string $number, int $total, string $currency = 'GBP', ?string $issued = '2026-05-15', string $status = 'issued'): Invoice
    {
        return Invoice::query()->create([
            'status' => $status, 'number' => $number, 'client_id' => $job->client_id, 'work_job_id' => $job->id, 'currency' => $currency, 'total_minor' => $total,
            'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@example.test', 'issue_date' => $issued,
            'due_date' => $issued ? CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d') : null, 'issued_at' => $issued ? now() : null,
            'notes' => 'Payment due within 30 days.',
        ]);
    }

    /** A line in your statements (money in unless negative), in another currency when the bank gave one. */
    private function payment(int $pence, string $name, string $on = '2026-05-28', ?string $currency = null, ?int $originalMinor = null, ?ImportBatch $batch = null): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => ($batch ?? $this->batch)->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'golden-pay-'.$this->n, 'original_currency' => $currency, 'original_amount_minor' => $originalMinor]);
    }

    /** Decided by you as business income for the job, optionally naming the invoice it pays. */
    private function decideIncome(BankTransaction|ManualExpense $source, WorkJob $job, ?Invoice $invoice = null): void
    {
        $lines = AllocationWriter::lines($source->amount_pence, 'business_income', $this->turnover(), $job->id);
        if ($invoice) {
            $lines[0]['invoice_id'] = $invoice->id;
        }
        $this->assertTrue($this->writer()->decide($source, $lines));
    }

    /** The matcher's suggestion that a payment is for an invoice of exactly its amount, left open (as the Suggester leaves it). */
    private function suggestInvoice(BankTransaction $tx, Invoice $invoice): void
    {
        $lines = AllocationWriter::lines($tx->amount_pence, 'business_income', $this->turnover(), $invoice->work_job_id);
        $lines[0]['invoice_id'] = $invoice->id;
        $this->assertTrue($this->writer()->decide($tx, $lines, 'matcher', false, "Matches invoice {$invoice->number}", null,
            new Provenance('suggest.invoice', ['inv:'.$invoice->id, 'job:'.$invoice->work_job_id], 1)));
    }

    /** @param  array<int, array<string, int>>  $received */
    private static function three(array $received, Invoice $invoice): array
    {
        return Arr::only($received[$invoice->id], ['paid', 'elsewhere', 'waiting']);
    }

    public function test_received_for_todays_ledger_shapes_is_pinned(): void
    {
        // Two invoices on one job, paid oldest first by issue date (INV-0002 was issued first, though made second).
        $arena = $this->job('Arena load-in');
        $a1 = $this->invoice($arena, 'INV-0001', 45000, issued: '2026-05-20');
        $a2 = $this->invoice($arena, 'INV-0002', 30000, issued: '2026-05-15');
        $this->decideIncome($this->payment(60000, 'Acme Events Ltd'), $arena);
        // A draft on the same job is never paid by its income.
        $draft = $this->invoice($arena, null, 10000, issued: null, status: 'draft');

        // An exact link: the matcher's suggestion for an invoice of the same amount, accepted by you.
        $gala = $this->job('Gala dinner');
        $b1 = $this->invoice($gala, 'INV-0003', 120000, issued: '2026-05-25');
        $tx = $this->payment(120000, 'Acme Events Ltd');
        $this->suggestInvoice($tx, $b1);
        $this->writer()->confirm($tx);

        // A euro invoice paid in euros: €1,000.00 into the euro pocket, £850.00 by the bank's conversion.
        $berlin = $this->job('Berlin expo');
        $c1 = $this->invoice($berlin, 'INV-0004', 100000, 'EUR');
        $this->decideIncome($this->payment(85000, 'Acme Events GmbH', currency: 'EUR', originalMinor: 100000), $berlin);

        // A pound invoice paid partly in euros: €700.00 (£602.35) pays the €400.00 invoice, and the pound invoice takes
        // what's left by its value in pounds.
        $paris = $this->job('Paris show');
        $d1 = $this->invoice($paris, 'INV-0005', 40000, 'EUR', '2026-05-10');
        $d2 = $this->invoice($paris, 'INV-0006', 50000, 'GBP', '2026-05-12');
        $this->decideIncome($this->payment(60235, 'Acme Events SARL', currency: 'EUR', originalMinor: 70000), $paris);

        // A euro invoice with pound money for its job: given elsewhere, in pounds, never guessed.
        $vienna = $this->job('Vienna gala');
        $e1 = $this->invoice($vienna, 'INV-0007', 100000, 'EUR');
        $this->decideIncome($this->payment(85000, 'Acme Events AG'), $vienna);

        // A payment linked to an invoice that was then voided: shown on the void one, and paying its replacement
        // through the job.
        $leeds = $this->job('Leeds conference');
        $f1 = $this->invoice($leeds, 'INV-0008', 40000, issued: '2026-05-01');
        $this->decideIncome($this->payment(40000, 'Acme Events Ltd'), $leeds, $f1);
        $f1->update(['status' => 'void', 'void_reason' => 'Wrong day rate']);
        $f2 = $this->invoice($leeds, 'INV-0009', 40000, issued: '2026-05-05');

        // Lines of a held statement count nowhere: one linked to the invoice, one for its job.
        $manchester = $this->job('Manchester awards');
        $g1 = $this->invoice($manchester, 'INV-0010', 25000);
        $held = ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'held.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $linkedHeld = $this->payment(10000, 'Acme Events Ltd', batch: $held);
        $this->decideIncome($linkedHeld, $manchester, $g1);
        $jobHeld = $this->payment(15000, 'Acme Events Ltd', batch: $held);
        $this->decideIncome($jobHeld, $manchester);
        $held->forceFill(['status' => 'quarantined'])->save();
        foreach ([$linkedHeld, $jobHeld] as $t) {
            $t->forceFill(['state' => 'quarantined'])->save();
        }

        // Waiting: an open suggestion linking a payment to the invoice, and an open suggestion of income for the job
        // (waiting on every invoice of the job).
        $birmingham = $this->job('Birmingham launch');
        $h1 = $this->invoice($birmingham, 'INV-0011', 20000, issued: '2026-05-03');
        $h2 = $this->invoice($birmingham, 'INV-0012', 30000, issued: '2026-05-04');
        $this->suggestInvoice($this->payment(20000, 'Acme Events Ltd'), $h1);
        $gathered = $this->payment(30000, 'Acme Events Ltd');
        $this->assertTrue($this->writer()->decide($gathered, AllocationWriter::lines(30000, 'business_income', $this->turnover(), $birmingham->id), 'matcher', false,
            'Paid for Birmingham launch', null, new Provenance('jobs.gather', ['job:'.$birmingham->id])));

        // A refund to the client on the job lowers what its income pays.
        $cardiff = $this->job('Cardiff tour');
        $i1 = $this->invoice($cardiff, 'INV-0013', 50000);
        $this->decideIncome($this->payment(50000, 'Acme Events Ltd'), $cardiff);
        $this->decideIncome($this->payment(-10000, 'Refund Acme Events', '2026-06-01'), $cardiff);

        // Income you added by hand (cash at the door).
        $bristol = $this->job('Bristol fair');
        $j1 = $this->invoice($bristol, 'INV-0014', 15000);
        $cash = ManualExpense::query()->create(['spent_on' => '2026-05-30', 'tax_year' => '2026-27', 'description' => 'Cash from Acme Events', 'merchant_key' => 'ACME EVENTS',
            'paid_with' => 'cash', 'amount_pence' => 15000]);
        $this->decideIncome($cash, $bristol);

        $all = ['INV-0001' => $a1, 'INV-0002' => $a2, 'draft' => $draft, 'INV-0003' => $b1, 'INV-0004' => $c1, 'INV-0005' => $d1, 'INV-0006' => $d2,
            'INV-0007' => $e1, 'INV-0008' => $f1->fresh(), 'INV-0009' => $f2, 'INV-0010' => $g1, 'INV-0011' => $h1, 'INV-0012' => $h2, 'INV-0013' => $i1, 'INV-0014' => $j1];
        $received = InvoicePayments::received(array_values($all));
        $this->assertEqualsCanonicalizing(array_map(fn (Invoice $i) => $i->id, array_values($all)), array_keys($received), 'One entry for each invoice asked about, and no other');

        $this->assertSame([
            'INV-0001' => ['paid' => 30000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0002' => ['paid' => 30000, 'elsewhere' => 0, 'waiting' => 0],
            'draft' => ['paid' => 0, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0003' => ['paid' => 120000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0004' => ['paid' => 100000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0005' => ['paid' => 40000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0006' => ['paid' => 25815, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0007' => ['paid' => 0, 'elsewhere' => 85000, 'waiting' => 0],
            'INV-0008' => ['paid' => 40000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0009' => ['paid' => 40000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0010' => ['paid' => 0, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0011' => ['paid' => 0, 'elsewhere' => 0, 'waiting' => 50000],
            'INV-0012' => ['paid' => 0, 'elsewhere' => 0, 'waiting' => 30000],
            'INV-0013' => ['paid' => 40000, 'elsewhere' => 0, 'waiting' => 0],
            'INV-0014' => ['paid' => 15000, 'elsewhere' => 0, 'waiting' => 0],
        ], array_map(fn (Invoice $i) => self::three($received, $i), $all));

        // Asked about one invoice at a time, each gives the same: its job's other invoices still take their turn.
        foreach ($all as $label => $invoice) {
            $this->assertSame(self::three($received, $invoice), self::three(InvoicePayments::received([$invoice]), $invoice), $label.' asked alone');
        }
        $this->assertSame([], InvoicePayments::received([]));

        // paid() and the model's paidMinor() say the same; an invoice not saved has nothing.
        $this->assertSame([30000, 30000, 25815, 40000], [InvoicePayments::paid($a1), $a1->paidMinor(), InvoicePayments::paid($d2), $f2->paidMinor()]);
        $this->assertSame(0, InvoicePayments::paid(new Invoice(['total_minor' => 100])));
        $this->assertSame(['GBP', 'EUR', 'GBP'], [InvoicePayments::currencyOf($a1), InvoicePayments::currencyOf($c1), InvoicePayments::currencyOf(new Invoice)]);
    }
}
