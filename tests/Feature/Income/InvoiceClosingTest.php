<?php

namespace Tests\Feature\Income;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoiceClosing;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Build slice S3 (income v3): InvoiceClosing. Closing writes off what's still to come, for a reason, with your note in
 * the history; it waits while a payment that may be for it is in the Inbox; it writes no ledger line, so a locked year
 * doesn't stop it. Reopening undoes it. A void invoice can be given the same client's invoice that replaced it, never
 * itself or one that leads back to it, and its payments then count there. Only you do any of it.
 */
class InvoiceClosingTest extends TestCase
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

    private function closing(): InvoiceClosing
    {
        return app(InvoiceClosing::class);
    }

    private function job(string $title, ?Client $client = null): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => ($client ?? $this->acme)->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
    }

    private function invoice(?WorkJob $job, ?string $number, int $total, string $status = 'issued', string $issued = '2026-05-15', ?Client $client = null): Invoice
    {
        return Invoice::query()->create(['status' => $status, 'number' => $number, 'client_id' => ($client ?? $this->acme)->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => ($client ?? $this->acme)->name, 'issue_date' => $issued, 'due_date' => '2026-06-14']);
    }

    private function tx(int $pence, string $on = '2026-05-28'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => 'ACME PAYROLL', 'counterparty' => 'ACME PAYROLL', 'merchant_key' => 'ACME PAYROLL', 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'closing-'.$this->n]);
    }

    private function income(int $pence, ?Invoice $invoice = null, ?WorkJob $job = null): array
    {
        return ['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'), 'amount_pence' => $pence,
            'invoice_id' => $invoice?->id, 'work_job_id' => $job?->id];
    }

    private function paid(Invoice $invoice, int $pence, string $on = '2026-05-28'): BankTransaction
    {
        $tx = $this->tx($pence, $on);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income($pence, $invoice)]));

        return $tx;
    }

    private function refusal(callable $do): ?string
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function state(Invoice $invoice): array
    {
        return Arr::only(InvoicePayments::received([$invoice->fresh()])[$invoice->id], ['paid', 'closed', 'outstanding', 'state']);
    }

    public function test_close_writes_off_what_is_still_to_come_and_keeps_your_note(): void
    {
        $show = $this->job('Show A');
        $invoice = $this->invoice($show, 'INV-0041', 100000);
        $this->paid($invoice, 97000);

        $this->closing()->close($invoice, 'short_paid', 'They paid less: agreed on the phone.');

        $fresh = $invoice->fresh();
        $this->assertSame([3000, 'short_paid', 97000], [$fresh->closed_minor, $fresh->closed_reason, $fresh->closed_linked_minor], 'and what payments naming it had paid by then');
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame(['paid' => 97000, 'closed' => 3000, 'outstanding' => 0, 'state' => 'closed'], $this->state($invoice));
        $event = AuditEvent::query()->where('action', 'invoice.closed')->sole();
        $this->assertSame('They paid less: agreed on the phone.', $event->reason);
        $this->assertSame(['closed_minor' => null, 'closed_reason' => null, 'closed_at' => null], $event->before);
        $this->assertSame([3000, 'short_paid', 97000, 3000], [$event->after['closed_minor'], $event->after['closed_reason'], $event->after['closed_linked_minor'], $event->after['outstanding']]);
        $this->assertSame('you', $event->actor);
        $this->assertTrue(AuditLog::verify()['ok']);

        // Closed already; and reopening owes it again.
        $this->assertSame('INV-0041 is already closed.', $this->refusal(fn () => $this->closing()->close($invoice, 'other')));
        $this->closing()->reopen($invoice, 'They’ll pay the rest after all');
        $this->assertSame([null, null, null, null], [$invoice->fresh()->closed_minor, $invoice->fresh()->closed_reason, $invoice->fresh()->closed_at, $invoice->fresh()->closed_linked_minor]);
        $this->assertSame(97000, AuditEvent::query()->where('action', 'invoice.reopened')->sole()->before['closed_linked_minor']);
        $this->assertSame(['paid' => 97000, 'closed' => 0, 'outstanding' => 3000, 'state' => 'part_paid'], $this->state($invoice));
        $this->assertSame(1, AuditEvent::query()->where('action', 'invoice.reopened')->count());
        $this->assertSame('INV-0041 isn’t closed.', $this->refusal(fn () => $this->closing()->reopen($invoice)));

        // Paid in another currency: closed counts as paid.
        $this->closing()->close($invoice, 'paid_other_currency');
        $this->assertSame('paid', $this->state($invoice)['state']);
        $this->assertSame(0, Allocation::query()->where('invoice_id', $invoice->id)->where('kind', '!=', 'business_income')->count(), 'no ledger line is written');
    }

    public function test_close_is_refused_on_void_draft_or_paid_invoices_and_for_a_reason_it_doesnt_know(): void
    {
        $void = $this->invoice(null, 'INV-0001', 50000, 'void');
        $draft = $this->invoice(null, null, 50000, 'draft');
        $full = $this->invoice(null, 'INV-0002', 50000);
        $this->paid($full, 50000);
        $legacy = $this->invoice(null, 'INV-0003', 50000, 'paid');
        $open = $this->invoice(null, 'INV-0004', 50000);

        $this->assertSame('INV-0001 is void: there’s nothing to close.', $this->refusal(fn () => $this->closing()->close($void, 'wont_chase')));
        $this->assertSame('This invoice is a draft: there’s nothing to close.', $this->refusal(fn () => $this->closing()->close($draft, 'wont_chase')));
        $this->assertSame('INV-0002 is paid in full: there’s nothing to close.', $this->refusal(fn () => $this->closing()->close($full, 'wont_chase')));
        $this->assertSame('INV-0003 is already marked paid: there’s nothing to close.', $this->refusal(fn () => $this->closing()->close($legacy, 'wont_chase')));
        $this->assertSame('Say why it’s being closed.', $this->refusal(fn () => $this->closing()->close($open, 'because')));
        $this->assertSame(0, AuditEvent::query()->where('action', 'invoice.closed')->count());
    }

    public function test_close_waits_for_a_payment_in_the_inbox_that_may_be_for_it(): void
    {
        // A waiting payment naming it.
        $loose = $this->invoice(null, 'INV-0010', 60000);
        $tx = $this->tx(60000);
        app(AllocationWriter::class)->decide($tx, [$this->income(60000, $loose)], 'matcher', false, 'Matches invoice INV-0010', null, new Provenance('suggest.invoice', ['inv:'.$loose->id], 1));
        $this->assertSame('Decide the payment waiting in the Inbox first: it may be for INV-0010.', $this->refusal(fn () => $this->closing()->close($loose, 'wont_chase')));

        // Income for the job waiting, and this is the job's oldest invoice still owed: refused.
        $show = $this->job('Show A');
        $first = $this->invoice($show, 'INV-0011', 40000, 'issued', '2026-05-10');
        $second = $this->invoice($show, 'INV-0012', 40000, 'issued', '2026-05-20');
        $waiting = $this->tx(40000);
        app(AllocationWriter::class)->decide($waiting, [$this->income(40000, null, $show)], 'matcher', false, 'A business cost on Show A', null, new Provenance('jobs.gather', ['job:'.$show->id]));
        $this->assertNotNull($this->refusal(fn () => $this->closing()->close($first, 'wont_chase')));

        // The newer one isn't what that money would pay first: it can be closed.
        $this->closing()->close($second, 'wont_chase');
        $this->assertSame(40000, $second->fresh()->closed_minor);
    }

    public function test_closing_and_replacing_dont_check_a_locked_year(): void
    {
        $invoice = $this->invoice(null, 'INV-0020', 50000, 'issued', '2025-05-15');
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();

        $this->closing()->close($invoice, 'charges');
        $this->assertSame(50000, $invoice->fresh()->closed_minor);
        $this->assertSame(0, Allocation::query()->count(), 'no ledger line');
    }

    public function test_only_you_close_reopen_or_replace(): void
    {
        $invoice = $this->invoice(null, 'INV-0030', 50000);
        foreach (['policy', 'matcher', 'ai'] as $actor) {
            $this->assertSame('Only you can close an invoice.', $this->refusal(fn () => AuditContext::with($actor, fn () => $this->closing()->close($invoice, 'wont_chase'))));
        }
        $this->assertNull($invoice->fresh()->closed_minor);
    }

    public function test_replaced_by_needs_the_same_client_and_never_itself_or_a_circle(): void
    {
        $show = $this->job('Show A');
        $void = $this->invoice($show, 'INV-0041', 50000);
        $this->paid($void, 50000);
        $void->update(['status' => 'void', 'void_reason' => 'Wrong rate']);
        $other = $this->invoice(null, 'INV-0050', 50000, 'issued', '2026-05-20', Client::query()->create(['name' => 'Other Ltd']));
        $draft = $this->invoice(null, null, 50000, 'draft');
        $issued = $this->invoice($show, 'INV-0043', 50000, 'issued', '2026-05-30');

        $this->assertSame('An invoice can’t replace itself.', $this->refusal(fn () => $this->closing()->replaceWith($void, $void)));
        $this->assertSame('INV-0050 is for another client: pick one of Acme Events’s invoices.', $this->refusal(fn () => $this->closing()->replaceWith($void, $other)));
        $this->assertSame('This invoice isn’t issued: pick an issued invoice.', $this->refusal(fn () => $this->closing()->replaceWith($void, $draft)));
        $this->assertSame('Only a void invoice can say which invoice replaced it.', $this->refusal(fn () => $this->closing()->replaceWith($issued, $void)));

        $this->closing()->replaceWith($void, $issued, 'Re-issued at the right rate');
        $this->assertSame($issued->id, $void->fresh()->replaced_by_id);
        $event = AuditEvent::query()->where('action', 'invoice.replaced')->sole();
        $this->assertSame([['replaced_by_id' => null], ['replaced_by_id' => $issued->id], 'Re-issued at the right rate'], [$event->before, $event->after, $event->reason]);
        $this->assertSame(['paid' => 50000, 'closed' => 0, 'outstanding' => 0, 'state' => 'paid'], $this->state($issued), 'its payment counts for the replacement');

        // A chain: the replacement voided in turn and replaced again; the payment follows to the end.
        $issued->update(['status' => 'void', 'void_reason' => 'Wrong PO']);
        $third = $this->invoice($show, 'INV-0044', 50000, 'issued', '2026-06-02');
        // INV-0043 can't be replaced by INV-0041 (void), and never in a circle.
        $this->assertSame('INV-0041 isn’t issued: pick an issued invoice.', $this->refusal(fn () => $this->closing()->replaceWith($issued, $void)));
        $this->closing()->replaceWith($issued, $third);
        $this->assertSame('paid', $this->state($third)['state']);

        // Saying none did takes it off again.
        $this->closing()->replaceWith($void, null);
        $this->assertNull($void->fresh()->replaced_by_id);
        $this->assertSame(2 + 1, AuditEvent::query()->where('action', 'invoice.replaced')->count());
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_an_invoice_with_no_client_is_replaced_by_one_with_the_same_bill_to_name(): void
    {
        $a = Invoice::query()->create(['status' => 'void', 'number' => 'INV-0060', 'currency' => 'GBP', 'total_minor' => 1000, 'bill_to_name' => 'Acme Events Ltd', 'issue_date' => '2026-05-01']);
        $b = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0061', 'currency' => 'GBP', 'total_minor' => 1000, 'bill_to_name' => 'ACME EVENTS', 'issue_date' => '2026-05-02']);
        $c = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0062', 'currency' => 'GBP', 'total_minor' => 1000, 'bill_to_name' => 'Someone Else', 'issue_date' => '2026-05-02']);

        $this->assertNotNull($this->refusal(fn () => $this->closing()->replaceWith($a, $c)));
        $this->closing()->replaceWith($a, $b);
        $this->assertSame($b->id, $a->fresh()->replaced_by_id);
    }

    public function test_the_invoice_guard_allows_closing_only_while_issued_and_a_replacement_only_on_a_void_invoice(): void
    {
        $draft = $this->invoice(null, null, 50000, 'draft');
        $issued = $this->invoice(null, 'INV-0070', 50000);
        $void = $this->invoice(null, 'INV-0071', 50000, 'void');

        $refused = function (callable $do): ?string {
            try {
                $do();
            } catch (LogicException $e) {
                return $e->getMessage();
            }

            return null;
        };
        $this->assertSame('Only an issued invoice can be closed or reopened.', $refused(fn () => $draft->fresh()->update(['closed_minor' => 100, 'closed_reason' => 'other'])));
        $this->assertSame('Only an issued invoice can be closed or reopened.', $refused(fn () => $void->fresh()->update(['closed_minor' => 100, 'closed_reason' => 'other'])));
        $this->assertSame('Only a void invoice can say which invoice replaced it.', $refused(fn () => $issued->fresh()->update(['replaced_by_id' => $void->id])));
        $this->assertNull($refused(fn () => $issued->fresh()->update(['closed_minor' => 100, 'closed_reason' => 'other', 'closed_at' => now()])));
        $this->assertNull($refused(fn () => $void->fresh()->update(['replaced_by_id' => $issued->id])));
    }
}
