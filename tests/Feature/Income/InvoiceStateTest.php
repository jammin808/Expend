<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoiceClosing;
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
 * Build slice S2 (income v3's invoice counting): InvoicePayments::received() works out each invoice's state from what
 * came in (paid, part paid, overpaid, closed, check the currency), with the amounts behind it; a closed invoice passes
 * its job's money on; payments for a voided invoice count for the invoice that replaced it, and stranded() lists those
 * that count nowhere.
 */
class InvoiceStateTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    private ?Client $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->client = Client::query()->create(['name' => 'Acme Events']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function job(string $title): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $this->client->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
    }

    private function invoice(?WorkJob $job, string $number, int $total, string $issued = '2026-05-15', string $currency = 'GBP'): Invoice
    {
        return Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $this->client->id, 'work_job_id' => $job?->id, 'currency' => $currency,
            'total_minor' => $total, 'bill_to_name' => 'Acme Events', 'issue_date' => $issued, 'due_date' => CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d')]);
    }

    private function payment(int $pence, string $on = '2026-05-28', ?string $currency = null, ?int $originalMinor = null): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;
        $name = $pence < 0 ? 'Refund Acme Events' : 'Acme Events Ltd';

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'state-'.$this->n, 'original_currency' => $currency, 'original_amount_minor' => $originalMinor]);
    }

    private function line(int $pence, ?Invoice $invoice = null, ?WorkJob $job = null): array
    {
        return ['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'), 'work_job_id' => $job?->id,
            'invoice_id' => $invoice?->id, 'amount_pence' => $pence];
    }

    /** Decided by you: the payment's lines as given. */
    private function decide(BankTransaction $tx, array ...$lines): BankTransaction
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines));

        return $tx;
    }

    /** A matcher's suggestion that the payment is for the invoice, left open. */
    private function suggest(BankTransaction $tx, Invoice $invoice): void
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->line($tx->amount_pence, $invoice)], 'matcher', false, "Matches invoice {$invoice->number}", null,
            new Provenance('suggest.invoice', ['inv:'.$invoice->id], 1)));
    }

    /** As InvoiceClosing::close() records it: what's still to come, and what payments naming it had paid by then. */
    private function close(Invoice $invoice, string $reason = 'short_paid'): void
    {
        $r = InvoicePayments::received([$invoice->fresh()])[$invoice->id];
        $invoice->fresh()->update(['closed_minor' => $r['outstanding'], 'closed_reason' => $reason, 'closed_at' => now(), 'closed_linked_minor' => $r['paid_linked']]);
    }

    private function void(Invoice $invoice, ?Invoice $replacedBy = null): void
    {
        $invoice->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong day rate', 'replaced_by_id' => $replacedBy?->id]);
    }

    /** @return array<string, int|string> the keys named, for one invoice */
    private function got(Invoice $invoice, array $keys = ['paid', 'outstanding', 'state']): array
    {
        return Arr::only(InvoicePayments::received([$invoice->fresh()])[$invoice->id], $keys);
    }

    public function test_paid_part_paid_overpaid_refunded_and_reversed(): void
    {
        // Two payments for an invoice with no job.
        $loose = $this->invoice(null, 'INV-0001', 100000);
        $this->decide($this->payment(60000), $this->line(60000, $loose));
        $this->assertSame(['paid' => 60000, 'paid_linked' => 60000, 'outstanding' => 40000, 'over' => 0, 'state' => 'part_paid'], $this->got($loose, ['paid', 'paid_linked', 'outstanding', 'over', 'state']));
        $this->decide($this->payment(40000, '2026-06-02'), $this->line(40000, $loose));
        $this->assertSame(['paid' => 100000, 'outstanding' => 0, 'state' => 'paid'], $this->got($loose));

        // One payment for two invoices.
        $arena = $this->job('Arena load-in');
        $gala = $this->job('Gala dinner');
        $a = $this->invoice($arena, 'INV-0002', 45000);
        $b = $this->invoice($gala, 'INV-0003', 30000);
        $this->decide($this->payment(75000), $this->line(45000, $a), $this->line(30000, $b));
        $this->assertSame([['paid' => 45000, 'outstanding' => 0, 'state' => 'paid'], ['paid' => 30000, 'outstanding' => 0, 'state' => 'paid']], [$this->got($a), $this->got($b)]);

        // One invoice for three shows, its payment's lines on each show: the other shows' own invoices are untouched.
        $leeds = $this->job('Leeds');
        $york = $this->job('York');
        $hull = $this->job('Hull');
        $tour = $this->invoice($leeds, 'INV-0004', 90000);
        $yorkOwn = $this->invoice($york, 'INV-0005', 20000);
        $hullOwn = $this->invoice($hull, 'INV-0006', 20000);
        $this->decide($this->payment(90000), $this->line(30000, $tour), $this->line(30000, $tour, $york), $this->line(30000, $tour, $hull));
        $received = InvoicePayments::received([$tour, $yorkOwn, $hullOwn]);
        $this->assertSame([[90000, 'paid'], [0, 'unpaid'], [0, 'unpaid']], [[$received[$tour->id]['paid'], $received[$tour->id]['state']],
            [$received[$yorkOwn->id]['paid'], $received[$yorkOwn->id]['state']], [$received[$hullOwn->id]['paid'], $received[$hullOwn->id]['state']]]);

        // A gross-up: £970 received for a £1,000 invoice, £30 taken off. The invoice is paid; the fee isn't counted.
        $net = $this->invoice($this->job('Cardiff'), 'INV-0007', 100000);
        $this->decide($this->payment(97000), $this->line(100000, $net),
            ['kind' => 'business_expense', 'category_id' => (int) Category::query()->where('code', 'finance')->value('id'), 'amount_pence' => -3000]);
        $this->assertSame(['paid' => 100000, 'outstanding' => 0, 'state' => 'paid'], $this->got($net));

        // Overpaid, then the extra paid back against it.
        $over = $this->invoice($this->job('Bristol'), 'INV-0008', 50000);
        $this->decide($this->payment(60000), $this->line(60000, $over));
        $this->assertSame(['paid' => 60000, 'refunded' => 0, 'outstanding' => 0, 'over' => 10000, 'state' => 'paid'], $this->got($over, ['paid', 'refunded', 'outstanding', 'over', 'state']));
        $this->decide($this->payment(-10000, '2026-06-05'), $this->line(-10000, $over));
        $this->assertSame(['paid' => 50000, 'paid_linked' => 50000, 'refunded' => 10000, 'outstanding' => 0, 'over' => 0, 'state' => 'paid'],
            $this->got($over, ['paid', 'paid_linked', 'refunded', 'outstanding', 'over', 'state']));

        // A payment reversed: nothing paid, the whole amount still to come.
        $bounced = $this->invoice($this->job('Bath'), 'INV-0009', 30000);
        $this->decide($this->payment(30000), $this->line(30000, $bounced));
        $this->decide($this->payment(-30000, '2026-05-30'), $this->line(-30000, $bounced));
        $this->assertSame(['paid' => 0, 'refunded' => 30000, 'outstanding' => 30000, 'state' => 'unpaid'], $this->got($bounced, ['paid', 'refunded', 'outstanding', 'state']));
    }

    public function test_a_closed_invoice_shrinks_as_money_arrives_and_passes_job_money_on(): void
    {
        $show = $this->invoice($this->job('Arena load-in'), 'INV-0001', 100000);
        $this->decide($this->payment(97000), $this->line(97000, $show));
        $this->close($show);
        $this->assertSame(['paid' => 97000, 'closed' => 3000, 'outstanding' => 0, 'state' => 'closed'], $this->got($show, ['paid', 'closed', 'outstanding', 'state']));
        // Money that names it later shrinks what was written off, until nothing is.
        $this->decide($this->payment(2000, '2026-06-01'), $this->line(2000, $show));
        $this->assertSame(['paid' => 99000, 'closed' => 1000, 'outstanding' => 0, 'state' => 'closed'], $this->got($show, ['paid', 'closed', 'outstanding', 'state']));
        $this->decide($this->payment(1000, '2026-06-02'), $this->line(1000, $show));
        $this->assertSame(['paid' => 100000, 'closed' => 0, 'outstanding' => 0, 'state' => 'paid'], $this->got($show, ['paid', 'closed', 'outstanding', 'state']));

        // The critic's case: INV-1 £1,000, £970 of job income, closed with £30 written off; INV-2 £500 on the same job;
        // then £500 more for the job. INV-1 keeps only what it had, so INV-2 comes out paid.
        $gala = $this->job('Gala dinner');
        $one = $this->invoice($gala, 'INV-0002', 100000, '2026-05-01');
        $this->decide($this->payment(97000), $this->line(97000, null, $gala));
        $this->close($one, 'charges');
        $two = $this->invoice($gala, 'INV-0003', 50000, '2026-05-10');
        $this->assertSame(['paid' => 0, 'outstanding' => 50000, 'state' => 'unpaid'], $this->got($two));
        $this->decide($this->payment(50000, '2026-06-03'), $this->line(50000, null, $gala));
        $received = InvoicePayments::received([$one->fresh(), $two]);
        $this->assertSame(['paid' => 97000, 'closed' => 3000, 'outstanding' => 0, 'state' => 'closed'], Arr::only($received[$one->id], ['paid', 'closed', 'outstanding', 'state']));
        $this->assertSame(['paid' => 50000, 'closed' => 0, 'outstanding' => 0, 'state' => 'paid'], Arr::only($received[$two->id], ['paid', 'closed', 'outstanding', 'state']));
        $this->assertSame(Arr::only($received[$two->id], ['paid', 'state']), $this->got($two, ['paid', 'state']), 'Asked about alone');

        // Paid in another currency and closed for that: paid.
        $abroad = $this->invoice($this->job('Paris'), 'INV-0004', 100000);
        $this->decide($this->payment(90000), $this->line(90000, $abroad));
        $this->close($abroad, 'paid_other_currency');
        $this->assertSame(['paid' => 90000, 'closed' => 10000, 'outstanding' => 0, 'state' => 'paid'], $this->got($abroad, ['paid', 'closed', 'outstanding', 'state']));

        // Reopened: owed again.
        $abroad->fresh()->update(['closed_minor' => null, 'closed_reason' => null, 'closed_at' => null, 'closed_linked_minor' => null]);
        $this->assertSame(['paid' => 90000, 'closed' => 0, 'outstanding' => 10000, 'state' => 'part_paid'], $this->got($abroad, ['paid', 'closed', 'outstanding', 'state']));
    }

    /**
     * Stage 1 check: an invoice paid partly from its job's income and closed keeps that job income when money naming it
     * comes in afterwards. The money naming it shrinks what was written off; it never pushes the job's income on to the
     * job's next invoice.
     */
    public function test_money_naming_a_closed_invoice_shrinks_the_write_off_not_its_share_of_the_job_income(): void
    {
        // £300 for the job (no invoice named) on a £1,000 invoice, closed with £700 written off; then they pay the £700
        // naming it after all.
        $arena = $this->job('Arena load-in');
        $big = $this->invoice($arena, 'INV-0001', 100000, '2026-05-02');
        $this->decide($this->payment(30000, '2026-05-10'), $this->line(30000, null, $arena));
        app(InvoiceClosing::class)->close($big, 'wont_chase', 'They said the rest is not coming');
        $this->assertSame([70000, 0], [$big->fresh()->closed_minor, $big->fresh()->closed_linked_minor]);
        $this->assertSame(['paid' => 30000, 'closed' => 70000, 'outstanding' => 0, 'state' => 'closed'], $this->got($big, ['paid', 'closed', 'outstanding', 'state']));
        $this->decide($this->payment(40000, '2026-06-01'), $this->line(40000, $big));
        $this->assertSame(['paid' => 70000, 'closed' => 30000, 'outstanding' => 0, 'state' => 'closed'], $this->got($big, ['paid', 'closed', 'outstanding', 'state']), 'part of it');
        $this->decide($this->payment(30000, '2026-06-02'), $this->line(30000, $big));
        $this->assertSame(['paid' => 100000, 'closed' => 0, 'outstanding' => 0, 'state' => 'paid'], $this->got($big, ['paid', 'closed', 'outstanding', 'state']));

        // £970 for the job on INV-0002 (£1,000), closed £30 short; INV-0003 (£500) on the same job; then the £30 comes in
        // naming INV-0002. INV-0002 is paid, and nothing moves on to INV-0003, which nobody paid.
        $gala = $this->job('Gala dinner');
        $one = $this->invoice($gala, 'INV-0002', 100000, '2026-05-01');
        $two = $this->invoice($gala, 'INV-0003', 50000, '2026-05-15');
        $this->decide($this->payment(97000, '2026-05-20'), $this->line(97000, null, $gala));
        app(InvoiceClosing::class)->close($one, 'short_paid', 'They paid £970');
        $this->decide($this->payment(3000, '2026-06-01'), $this->line(3000, $one));
        $received = InvoicePayments::received([$one->fresh(), $two->fresh()]);
        $this->assertSame([['paid' => 100000, 'closed' => 0, 'outstanding' => 0, 'state' => 'paid'], ['paid' => 0, 'closed' => 0, 'outstanding' => 50000, 'state' => 'unpaid']],
            [Arr::only($received[$one->id], ['paid', 'closed', 'outstanding', 'state']), Arr::only($received[$two->id], ['paid', 'closed', 'outstanding', 'state'])]);
        $this->assertSame(['paid' => 0, 'state' => 'unpaid'], $this->got($two, ['paid', 'state']), 'Asked about alone');

        // More job income still passes on to INV-0003, as for any closed invoice.
        $this->decide($this->payment(50000, '2026-06-03'), $this->line(50000, null, $gala));
        $this->assertSame(['paid' => 50000, 'state' => 'paid'], $this->got($two, ['paid', 'state']));
        $this->assertSame(['paid' => 100000, 'state' => 'paid'], $this->got($one, ['paid', 'state']));

        // Money naming it before the closing was counted then: re-saving that payment later changes nothing, and the
        // job's income still passes on to its next invoice.
        $cardiff = $this->job('Cardiff');
        $linked = $this->invoice($cardiff, 'INV-0004', 100000, '2026-05-01');
        $later = $this->invoice($cardiff, 'INV-0005', 20000, '2026-05-20');
        $first = $this->decide($this->payment(70000, '2026-05-05'), $this->line(70000, $linked));
        app(InvoiceClosing::class)->close($linked, 'short_paid');
        $this->assertSame(70000, $linked->fresh()->closed_linked_minor);
        $this->decide($this->payment(20000, '2026-06-01'), $this->line(20000, null, $cardiff));
        $this->decide($first, $this->line(70000, $linked) + ['note' => 'Paid by bank transfer']);
        $this->assertSame([['paid' => 70000, 'closed' => 30000, 'state' => 'closed'], ['paid' => 20000, 'closed' => 0, 'state' => 'paid']],
            [$this->got($linked, ['paid', 'closed', 'state']), $this->got($later, ['paid', 'closed', 'state'])]);

        // A refund after the closing never adds to what was written off: it's owed again, as it always was.
        $bath = $this->invoice($this->job('Bath'), 'INV-0006', 100000, '2026-05-01');
        $this->decide($this->payment(70000, '2026-05-05'), $this->line(70000, $bath));
        app(InvoiceClosing::class)->close($bath, 'short_paid');
        $this->decide($this->payment(-20000, '2026-06-05'), $this->line(-20000, $bath));
        $this->assertSame(['paid' => 50000, 'closed' => 30000, 'outstanding' => 20000, 'state' => 'part_paid'], $this->got($bath, ['paid', 'closed', 'outstanding', 'state']));
    }

    public function test_a_void_invoice_split_across_jobs_pays_no_other_job_and_is_stranded(): void
    {
        $leeds = $this->job('Leeds');
        $york = $this->job('York');
        $hull = $this->job('Hull');
        $void = $this->invoice($leeds, 'INV-0001', 90000, '2026-05-01');
        $this->decide($this->payment(90000), $this->line(30000, $void), $this->line(30000, $void, $york), $this->line(30000, $void, $hull));
        $this->assertSame([], InvoicePayments::stranded());
        $this->void($void);
        $leedsNew = $this->invoice($leeds, 'INV-0002', 30000, '2026-05-05');
        $yorkOwn = $this->invoice($york, 'INV-0003', 30000);
        $hullOwn = $this->invoice($hull, 'INV-0004', 30000);

        $received = InvoicePayments::received([$void->fresh(), $leedsNew, $yorkOwn, $hullOwn]);
        $this->assertSame([
            'INV-0001' => ['paid' => 90000, 'outstanding' => 0, 'state' => 'void'],
            'INV-0002' => ['paid' => 30000, 'outstanding' => 0, 'state' => 'paid'], // its own job's line, through the job
            'INV-0003' => ['paid' => 0, 'outstanding' => 30000, 'state' => 'unpaid'],
            'INV-0004' => ['paid' => 0, 'outstanding' => 30000, 'state' => 'unpaid'],
        ], ['INV-0001' => Arr::only($received[$void->id], ['paid', 'outstanding', 'state']), 'INV-0002' => Arr::only($received[$leedsNew->id], ['paid', 'outstanding', 'state']),
            'INV-0003' => Arr::only($received[$yorkOwn->id], ['paid', 'outstanding', 'state']), 'INV-0004' => Arr::only($received[$hullOwn->id], ['paid', 'outstanding', 'state'])]);
        $this->assertSame([$void->id => 60000], InvoicePayments::stranded());
        $this->assertSame([$void->id => 60000], InvoicePayments::stranded([$void]));
        $this->assertSame([], InvoicePayments::stranded([$leedsNew->id]));

        // A void invoice with no job: what was paid against it is stranded wherever the lines are, except as a job's
        // income; a line with no job counts nowhere.
        $loose = $this->invoice(null, 'INV-0005', 20000);
        $this->decide($this->payment(20000), $this->line(15000, $loose), $this->line(5000, $loose, $york));
        $this->void($loose);
        $this->assertSame([$void->id => 60000, $loose->id => 15000], InvoicePayments::stranded());
        $this->assertSame(['paid' => 5000, 'state' => 'part_paid'], $this->got($yorkOwn, ['paid', 'state']), 'York’s line joins its job’s income, as before');
    }

    public function test_a_replaced_invoice_counts_its_payments_for_the_replacement(): void
    {
        // Job-less: voided after it was paid, and replaced.
        $old = $this->invoice(null, 'INV-0001', 40000, '2026-05-01');
        $this->decide($this->payment(40000), $this->line(40000, $old));
        $this->void($old);
        $new = $this->invoice(null, 'INV-0002', 40000, '2026-05-10');
        $this->assertSame(['paid' => 0, 'state' => 'unpaid'], $this->got($new, ['paid', 'state']));
        $this->assertSame([$old->id => 40000], InvoicePayments::stranded());
        $old->fresh()->update(['replaced_by_id' => $new->id]);
        $this->assertSame(['paid' => 40000, 'paid_linked' => 40000, 'outstanding' => 0, 'state' => 'paid'], $this->got($new, ['paid', 'paid_linked', 'outstanding', 'state']));
        $this->assertSame(['paid' => 40000, 'state' => 'void'], $this->got($old, ['paid', 'state']), 'Still shown on the void one');
        $this->assertSame([], InvoicePayments::stranded());

        // Through two replacements.
        $third = $this->invoice(null, 'INV-0003', 40000, '2026-05-20');
        $this->void($new, $third);
        $this->assertSame(['paid' => 40000, 'state' => 'paid'], $this->got($third, ['paid', 'state']));
        $this->assertSame(['paid' => 0, 'state' => 'void'], $this->got($new, ['paid', 'state']));

        // On a job: counted for the replacement, never through the job's income (so the job's older invoice isn't paid).
        $arena = $this->job('Arena load-in');
        $older = $this->invoice($arena, 'INV-0004', 40000, '2026-04-01');
        $wrong = $this->invoice($arena, 'INV-0005', 40000, '2026-05-01');
        $this->decide($this->payment(40000), $this->line(40000, $wrong));
        $this->void($wrong);
        $right = $this->invoice($arena, 'INV-0006', 40000, '2026-05-02');
        $before = InvoicePayments::received([$older, $right]);
        $this->assertSame([40000, 0], [$before[$older->id]['paid'], $before[$right->id]['paid']], 'Not replaced: the job’s income, oldest first');
        $wrong->fresh()->update(['replaced_by_id' => $right->id]);
        $after = InvoicePayments::received([$older, $right]);
        $this->assertSame([[0, 'unpaid'], [40000, 'paid']], [[$after[$older->id]['paid'], $after[$older->id]['state']], [$after[$right->id]['paid'], $after[$right->id]['state']]]);

        // A loop (only possible by hand) is safe: followed until it comes back round, and counts nowhere.
        $x = $this->invoice(null, 'INV-0007', 10000);
        $y = $this->invoice(null, 'INV-0008', 10000);
        $this->decide($this->payment(10000), $this->line(10000, $x));
        $this->void($x);
        $this->void($y, $x);
        $x->fresh()->update(['replaced_by_id' => $y->id]);
        $this->assertSame(['paid' => 10000, 'state' => 'void'], $this->got($x, ['paid', 'state']));
        $this->assertSame(['paid' => 0, 'state' => 'void'], $this->got($y, ['paid', 'state']));
        $this->assertSame([$y->id => 10000], InvoicePayments::stranded());
    }

    public function test_waiting_in_the_invoice_currency_and_unmeasured(): void
    {
        $berlin = $this->job('Berlin expo');
        $euros = $this->invoice($berlin, 'INV-0001', 100000, currency: 'EUR');
        // €1,000.00 that came in as £850.00, suggested for it; and a pound payment suggested for it too.
        $this->suggest($this->payment(85000, currency: 'EUR', originalMinor: 100000), $euros);
        $this->suggest($this->payment(30000), $euros);
        $this->assertSame(['paid' => 0, 'waiting' => 115000, 'waiting_linked' => 100000, 'waiting_unmeasured' => 30000, 'state' => 'unpaid'],
            $this->got($euros, ['paid', 'waiting', 'waiting_linked', 'waiting_unmeasured', 'state']));

        // A pound invoice measures everything in pence; job income waiting isn't linked.
        $leeds = $this->job('Leeds');
        $pounds = $this->invoice($leeds, 'INV-0002', 50000);
        $this->suggest($this->payment(50000), $pounds);
        $this->assertTrue(app(AllocationWriter::class)->decide($this->payment(20000), [$this->line(20000, null, $leeds)], 'matcher', false, 'Paid for Leeds', null,
            new Provenance('jobs.gather', ['job:'.$leeds->id])));
        $this->assertSame(['waiting' => 70000, 'waiting_linked' => 50000, 'waiting_unmeasured' => 0], $this->got($pounds, ['waiting', 'waiting_linked', 'waiting_unmeasured']));

        // Pound money for a euro invoice's job: check the currency.
        $vienna = $this->job('Vienna');
        $check = $this->invoice($vienna, 'INV-0003', 100000, currency: 'EUR');
        $this->decide($this->payment(85000), $this->line(85000, null, $vienna));
        $this->assertSame(['paid' => 0, 'elsewhere' => 85000, 'outstanding' => 100000, 'state' => 'check_currency'], $this->got($check, ['paid', 'elsewhere', 'outstanding', 'state']));
    }

    public function test_every_invoice_has_every_key_and_empty_holds_them_all(): void
    {
        $this->assertSame(['paid' => 0, 'elsewhere' => 0, 'waiting' => 0, 'paid_linked' => 0, 'refunded' => 0, 'waiting_linked' => 0, 'waiting_unmeasured' => 0,
            'closed' => 0, 'outstanding' => 0, 'over' => 0, 'state' => 'unpaid'], InvoicePayments::EMPTY);

        $issued = $this->invoice($this->job('Arena load-in'), 'INV-0001', 45000);
        $draft = Invoice::query()->create(['status' => 'draft', 'client_id' => $this->client->id, 'currency' => 'GBP', 'total_minor' => 30000, 'bill_to_name' => 'Acme Events']);
        $void = $this->invoice(null, 'INV-0002', 20000);
        $this->void($void);
        $received = InvoicePayments::received([$issued, $draft, $void->fresh()]);
        foreach ($received as $row) {
            $this->assertSame(array_keys(InvoicePayments::EMPTY), array_keys($row));
        }
        $this->assertSame([[45000, 'unpaid'], [0, 'draft'], [0, 'void']], array_map(fn ($row) => [$row['outstanding'], $row['state']], array_values($received)));
    }
}
