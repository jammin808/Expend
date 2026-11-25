<?php

namespace Tests\Feature\Invoices;

use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Build slice S2, InvoicePayments rule (v): a late cost of job A (a taxi linked after A's invoice went out) is charged on
 * the client's next invoice, for job B, as a line 'From earlier jobs' (invoice_lines.source_job_id = A). Income you
 * decide for job A without naming an invoice pays A's own invoices first; what's left pays those lines, oldest invoice
 * first, up to the lines' total. So a client paying 'for Arena load-in' covers the taxi on INV-0052 too, and reminders
 * don't chase money that has come in. Lines with no source_job_id (everything before this release) change nothing.
 */
class CrossJobPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    private Client $client;

    private WorkJob $arena;

    private Invoice $arenaInvoice;

    private WorkJob $gala;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-07-10 10:00:00');
        CarbonImmutable::setTestNow('2026-07-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->client = Client::query()->create(['name' => 'Acme Events']);
        $this->arena = $this->job('Arena load-in', '2026-06-03');
        $this->gala = $this->job('Gala dinner', '2026-06-20');
        // Arena's own invoice, issued and paid in full by a payment naming it.
        $this->arenaInvoice = $this->invoice($this->arena, 'INV-0041', [['Arena load-in (3 Jun 2026)', 65000]], '2026-06-04');
        $this->decide($this->payment(65000, '2026-06-20'), $this->line(65000, $this->arena, $this->arenaInvoice));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function job(string $title, string $on): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $this->client->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => 'done']);
    }

    /**
     * An invoice made as a draft with its lines, then issued (lines can only be written while it's a draft).
     *
     * @param  list<array{0:string, 1:int, 2?:?WorkJob, 3?:bool}>  $lines  description, amount, the job a cost came from, ticked
     */
    private function invoice(?WorkJob $job, ?string $number, array $lines, ?string $issued = '2026-06-25', string $currency = 'GBP', string $status = 'issued'): Invoice
    {
        $invoice = Invoice::query()->create(['status' => 'draft', 'client_id' => $this->client->id, 'work_job_id' => $job?->id, 'currency' => $currency,
            'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'notes' => 'Payment due within 30 days.']);
        $total = 0;
        foreach ($lines as $i => $line) {
            [$description, $amount] = $line;
            $from = $line[2] ?? null;
            $ticked = $line[3] ?? true;
            $invoice->allLines()->create(['description' => $description, 'quantity' => 1, 'unit_price_minor' => $amount, 'amount_minor' => $amount, 'sort' => $i,
                'source' => $from ? 'cost' : null, 'source_ref' => $from ? 'tx:'.(9000 + $i).'#j'.$from->id : null, 'source_job_id' => $from?->id, 'included' => $ticked]);
            $total += $ticked ? $amount : 0;
        }
        $invoice->update(['total_minor' => $total]);
        if ($status !== 'draft') {
            $invoice->update(['status' => $status, 'number' => $number, 'issue_date' => $issued, 'due_date' => CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d'), 'issued_at' => now()]);
        }

        return $invoice->fresh();
    }

    private function payment(int $pence, string $on = '2026-07-01'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'july.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => 'Acme Events Ltd', 'counterparty' => 'Acme Events Ltd', 'merchant_key' => 'ACME EVENTS LTD',
            'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'cross-'.$this->n]);
    }

    private function line(int $pence, ?WorkJob $job, ?Invoice $invoice = null): array
    {
        return ['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'), 'work_job_id' => $job?->id,
            'invoice_id' => $invoice?->id, 'amount_pence' => $pence];
    }

    private function decide(BankTransaction $tx, array ...$lines): void
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines));
    }

    /** @return array<string, array<string, int|string>> paid, waiting, outstanding and state by invoice number */
    private function figures(Invoice ...$invoices): array
    {
        $received = InvoicePayments::received($invoices);
        $out = [];
        foreach ($invoices as $invoice) {
            $out[$invoice->number ?? 'draft'] = Arr::only($received[$invoice->id], ['paid', 'waiting', 'outstanding', 'state']);
        }

        return $out;
    }

    public function test_income_left_on_a_job_pays_its_late_cost_on_another_invoice(): void
    {
        // Gala's invoice charges Arena's £41.60 taxi under 'From earlier jobs'.
        $galaInvoice = $this->invoice($this->gala, 'INV-0052', [['Gala dinner (20 Jun 2026)', 50000], ['Taxi to Arena load-in (3 Jun 2026)', 4160, $this->arena]]);
        $this->assertSame(['INV-0041' => ['paid' => 65000, 'waiting' => 0, 'outstanding' => 0, 'state' => 'paid'], 'INV-0052' => ['paid' => 0, 'waiting' => 0, 'outstanding' => 54160, 'state' => 'unpaid']],
            $this->figures($this->arenaInvoice, $galaInvoice));

        // The client pays £41.60 'for Arena load-in': you put it on Arena, naming no invoice.
        $this->decide($this->payment(4160), $this->line(4160, $this->arena));
        $this->assertSame(['INV-0041' => ['paid' => 65000, 'waiting' => 0, 'outstanding' => 0, 'state' => 'paid'], 'INV-0052' => ['paid' => 4160, 'waiting' => 0, 'outstanding' => 50000, 'state' => 'part_paid']],
            $this->figures($this->arenaInvoice, $galaInvoice));
        // Asked about alone, it's the same.
        $this->assertSame(['INV-0052' => ['paid' => 4160, 'waiting' => 0, 'outstanding' => 50000, 'state' => 'part_paid']], $this->figures($galaInvoice));

        // More than the lines from Arena: only up to their total; the rest stays Arena's.
        $this->decide($this->payment(10000, '2026-07-02'), $this->line(10000, $this->arena));
        $this->assertSame(4160, $this->figures($galaInvoice)['INV-0052']['paid']);
        // Gala's own income pays the rest of its invoice, as always.
        $this->decide($this->payment(50000, '2026-07-03'), $this->line(50000, $this->gala));
        $this->assertSame(['paid' => 54160, 'waiting' => 0, 'outstanding' => 0, 'state' => 'paid'], $this->figures($galaInvoice)['INV-0052']);

        // Income waiting for Arena may be for the taxi too.
        $this->assertTrue(app(AllocationWriter::class)->decide($this->payment(4160, '2026-07-04'), [$this->line(4160, $this->arena)], 'matcher', false, 'Paid for Arena load-in', null,
            new Provenance('jobs.gather', ['job:'.$this->arena->id])));
        $this->assertSame(4160, $this->figures($galaInvoice)['INV-0052']['waiting']);
    }

    public function test_the_jobs_own_invoices_come_first_then_the_oldest_invoice(): void
    {
        $show = $this->job('Show B', '2026-06-25');
        // Arena has a second invoice of its own, not paid yet: Arena's income pays that first.
        $extra = $this->invoice($this->arena, 'INV-0043', [['Arena extra day', 20000]], '2026-06-10');
        $later = $this->invoice($this->gala, 'INV-0052', [['Gala dinner', 50000], ['Taxi to Arena load-in', 4160, $this->arena]], '2026-06-25');
        $earlier = $this->invoice($show, 'INV-0050', [['Show B', 30000], ['Parking at Arena load-in', 3000, $this->arena]], '2026-06-22');
        $this->decide($this->payment(25000), $this->line(25000, $this->arena));

        // £250 for Arena: £200 to INV-0043, then £30 to INV-0050 (issued first), then £20 to INV-0052.
        $this->assertSame([20000, 3000, 2000], array_map(fn ($f) => $f['paid'], array_values($this->figures($extra, $earlier, $later))));
        $this->assertSame([3000, 2000], [$this->figures($earlier)['INV-0050']['paid'], $this->figures($later)['INV-0052']['paid']], 'Asked about alone');

        // A closed invoice takes only what it had: closing INV-0050's rest passes nothing more to it, and Arena's next
        // payment goes on to INV-0052.
        $earlier->fresh()->update(['closed_minor' => 30000, 'closed_reason' => 'wont_chase', 'closed_at' => now()]);
        $this->decide($this->payment(5000, '2026-07-02'), $this->line(5000, $this->arena));
        $this->assertSame([['paid' => 3000, 'waiting' => 0, 'outstanding' => 0, 'state' => 'closed'], ['paid' => 4160, 'waiting' => 0, 'outstanding' => 50000, 'state' => 'part_paid']],
            array_values($this->figures($earlier->fresh(), $later)));
    }

    public function test_only_ticked_lines_on_invoices_that_can_be_paid_take_from_another_job(): void
    {
        // Unticked: not charged, so nothing is paid to it.
        $unticked = $this->invoice($this->gala, 'INV-0052', [['Gala dinner', 50000], ['Taxi to Arena load-in', 4160, $this->arena, false]]);
        // A draft and a void invoice take nothing.
        $draft = $this->invoice($this->job('Show C', '2026-07-01'), null, [['Show C', 30000], ['Parking at Arena load-in', 3000, $this->arena]], status: 'draft');
        $void = $this->invoice($this->job('Show D', '2026-07-02'), 'INV-0053', [['Show D', 30000], ['Hotel near Arena', 9000, $this->arena]]);
        $void->update(['status' => 'void', 'void_reason' => 'Wrong client']);
        // A euro invoice can't measure pounds: nothing paid, never guessed.
        $euros = $this->invoice($this->job('Berlin', '2026-07-03'), 'INV-0054', [['Berlin expo', 100000], ['Arena flight', 12000, $this->arena]], currency: 'EUR');
        $this->decide($this->payment(30000), $this->line(30000, $this->arena));

        $this->assertSame([
            'INV-0052' => ['paid' => 0, 'waiting' => 0, 'outstanding' => 50000, 'state' => 'unpaid'],
            'draft' => ['paid' => 0, 'waiting' => 0, 'outstanding' => 0, 'state' => 'draft'],
            'INV-0053' => ['paid' => 0, 'waiting' => 0, 'outstanding' => 0, 'state' => 'void'],
            'INV-0054' => ['paid' => 0, 'waiting' => 0, 'outstanding' => 112000, 'state' => 'unpaid'],
        ], $this->figures($unticked, $draft, $void->fresh(), $euros));
    }

    public function test_without_lines_from_earlier_jobs_nothing_changes(): void
    {
        // The same invoices with the taxi typed as an ordinary line: Arena's income stays Arena's, as before this release.
        $typed = $this->invoice($this->gala, 'INV-0052', [['Gala dinner', 50000], ['Taxi to Arena load-in', 4160]]);
        $this->decide($this->payment(4160), $this->line(4160, $this->arena));
        $this->assertSame(['INV-0041' => ['paid' => 65000, 'waiting' => 0, 'outstanding' => 0, 'state' => 'paid'], 'INV-0052' => ['paid' => 0, 'waiting' => 0, 'outstanding' => 54160, 'state' => 'unpaid']],
            $this->figures($this->arenaInvoice, $typed));
        // A line from the invoice's own job is its own income's business, never counted twice.
        $own = $this->invoice($this->arena, 'INV-0055', [['Arena de-rig', 10000, $this->arena]], '2026-06-30');
        $this->assertSame(['paid' => 4160, 'waiting' => 0, 'outstanding' => 5840, 'state' => 'part_paid'], $this->figures($own)['INV-0055']);
    }
}
