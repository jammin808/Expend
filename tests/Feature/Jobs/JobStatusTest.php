<?php

namespace Tests\Feature\Jobs;

use App\Domain\Intel\AuditContext;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobStage;
use App\Domain\Jobs\JobStatus;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S4: JobStatus. Done, back to booked and Cancelled are yours only, audited (job.done, job.reopened,
 * job.cancelled, job.uncancelled), allowed in a locked year, and the job form's status goes through them. Done gets the
 * invoice ready (the open draft, or InvoiceService::draftForJob as it is today); back to booked removes only a draft
 * Done made that you haven't touched; a job made already done only offers "Draft its invoice?".
 */
class JobStatusTest extends TestCase
{
    use RefreshDatabase;

    private ?Client $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
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

    private function jobStatus(): JobStatus
    {
        return app(JobStatus::class);
    }

    private function job(array $fields = []): WorkJob
    {
        return WorkJob::query()->create($fields + ['title' => 'Arena load-in', 'client_id' => $this->client->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03',
            'fee_pence' => 65000, 'status' => 'booked']);
    }

    private function issued(WorkJob $job, string $number = 'INV-0041'): Invoice
    {
        return Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $job->client_id, 'work_job_id' => $job->id, 'currency' => 'GBP',
            'total_minor' => 65000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-06-04', 'due_date' => '2026-07-04']);
    }

    /** @return list<string> the job's audit actions, oldest first */
    private function events(WorkJob $job): array
    {
        return AuditEvent::query()->where('subject_type', 'WorkJob')->where('subject_id', $job->id)->orderBy('id')->pluck('action')->all();
    }

    public function test_done_sets_done_at_audits_it_and_gets_the_invoice_ready(): void
    {
        $job = $this->job();

        $r = $this->jobStatus()->markDone($job);

        $job->refresh();
        $this->assertSame(['done', '2026-06-10 10:00'], [$job->status, $job->done_at->format('Y-m-d H:i')]);
        $draft = Invoice::query()->where('work_job_id', $job->id)->sole();
        $this->assertSame([true, 'made', null, $draft->id, 'draft'], [$r['changed'], $r['draft'], $r['why'], $r['invoice']->id, $draft->status]);
        $this->assertSame('Marked done. Its invoice is ready to check.', $r['message']);
        $this->assertSame(['Arena load-in (3 Jun 2026)', 65000], [$draft->lines()->sole()->description, (int) $draft->total_minor], 'draftForJob as it is today');

        $event = AuditEvent::query()->where('action', 'job.done')->sole();
        $this->assertSame(['you', $job->id], [$event->actor, (int) $event->subject_id]);
        $this->assertSame(['status' => 'booked', 'done_at' => null], array_intersect_key($event->before, ['status' => 1, 'done_at' => 1]));
        $this->assertSame(['done', '2026-06-10 10:00:00', $draft->id], [$event->after['status'], $event->after['done_at'], $event->after['draft_made']]);
        $this->assertLessThan($event->id, AuditEvent::query()->where('action', 'invoice.drafted')->sole()->id);
    }

    public function test_done_reuses_an_open_draft_and_a_second_done_does_nothing(): void
    {
        $job = $this->job();
        $draft = app(InvoiceService::class)->draftForJob($job);

        $r = $this->jobStatus()->markDone($job);
        $this->assertSame(['reused', $draft->id], [$r['draft'], $r['invoice']->id]);
        $this->assertSame(1, Invoice::query()->count(), 'No second draft');
        $this->assertArrayNotHasKey('draft_made', AuditEvent::query()->where('action', 'job.done')->sole()->after);

        $again = $this->jobStatus()->markDone($job);
        $this->assertSame([false, 'Arena load-in is already done.'], [$again['changed'], $again['message']]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'job.done')->count());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_done_makes_no_draft_for_a_job_already_invoiced_paid_or_paid_without_an_invoice(): void
    {
        $invoiced = $this->job();
        $this->issued($invoiced);
        $r = $this->jobStatus()->markDone($invoiced);
        $this->assertSame([null, 'invoiced', 'Marked done. It’s already invoiced (INV-0041).'], [$r['draft'], $r['why'], $r['message']]);
        $this->assertSame(0, Invoice::query()->where('status', 'draft')->count());

        $paid = $this->job(['title' => 'Arena show']);
        $account = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $account->id, 'original_filename' => 'jun.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-05', 'tax_year' => '2026-27',
            'description' => 'Acme Events Ltd', 'counterparty' => 'Acme Events Ltd', 'merchant_key' => 'ACME EVENTS LTD', 'amount_pence' => 65000, 'raw' => [], 'fingerprint' => 'paid-1']);
        app(AllocationWriter::class)->decide($tx, [['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'),
            'work_job_id' => $paid->id, 'amount_pence' => 65000]]);
        $r = $this->jobStatus()->markDone($paid);
        $this->assertSame([null, 'paid', 'Marked done. Money for it has come in already, so no invoice was drafted.'], [$r['draft'], $r['why'], $r['message']]);

        $timesheet = Client::query()->create(['name' => 'Timesheet Crew', 'invoice_route' => 'none']);
        $r = $this->jobStatus()->markDone($this->job(['title' => 'Crew call', 'client_id' => $timesheet->id]));
        $this->assertSame([null, 'no_invoice', 'Marked done. Timesheet Crew pays without an invoice from you, so no invoice was drafted.'], [$r['draft'], $r['why'], $r['message']]);
        $this->assertSame(0, Invoice::query()->where('status', 'draft')->count());
    }

    public function test_a_deposit_doesnt_make_the_job_paid_and_done_drafts_for_the_rest(): void
    {
        $job = $this->job(['title' => 'Arena show']);
        $account = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-05-20', 'tax_year' => '2026-27',
            'description' => 'Acme Events Ltd', 'counterparty' => 'Acme Events Ltd', 'merchant_key' => 'ACME EVENTS LTD', 'amount_pence' => 10000, 'raw' => [], 'fingerprint' => 'deposit-1']);
        app(AllocationWriter::class)->decide($tx, [['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'),
            'work_job_id' => $job->id, 'amount_pence' => 10000]]);

        $stage = JobStage::of([$job->fresh()])[$job->id];
        $this->assertSame(['done_due', 'Done?'], [$stage['stage'], $stage['chip']], '£100 of £650 in: still asks');

        $r = $this->jobStatus()->markDone($job);
        $this->assertSame(['made', null, 'Marked done. £100 of £650 has come in for it: put what’s still owed on its draft.'], [$r['draft'], $r['why'], $r['message']]);

        // With its draft gone (back to booked removes it, untouched), done says part paid and offers a draft.
        $this->jobStatus()->reopen($job->fresh());
        $job->fresh()->update(['status' => 'done']);
        $this->assertSame(['part_paid', 'Part paid £100 of £650'], array_values(array_intersect_key(JobStage::of([$job->fresh()])[$job->id], ['stage' => 1, 'chip' => 1])));
        $this->assertTrue(JobStatus::offersDraft($job->fresh()));
    }

    public function test_done_with_no_fee_asks_what_you_charged_and_a_job_with_no_client_still_gets_a_draft(): void
    {
        $r = $this->jobStatus()->markDone($this->job(['fee_pence' => null]));
        $this->assertSame(['made', 'Marked done. What did you charge for it?'], [$r['draft'], $r['message']]);

        $r = $this->jobStatus()->markDone($this->job(['title' => 'Mystery gig', 'client_id' => null]));
        $this->assertSame(['made', 'Marked done. Its invoice is ready to check: say who it’s for first.'], [$r['draft'], $r['message']]);
    }

    public function test_done_is_refused_for_a_cancelled_job_and_for_automation(): void
    {
        $cancelled = $this->job(['status' => 'cancelled', 'cancel_charge' => 'none']);
        try {
            $this->jobStatus()->markDone($cancelled);
            $this->fail('A cancelled job was marked done');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Arena load-in was cancelled: set it back to booked before marking it done.', $e->getMessage());
        }

        $job = $this->job(['title' => 'Arena show']);
        foreach (['policy', 'matcher', 'ai'] as $actor) {
            foreach (['markDone' => fn () => $this->jobStatus()->markDone($job), 'reopen' => fn () => $this->jobStatus()->reopen($job),
                'cancel' => fn () => $this->jobStatus()->cancel($job, 'fee', 20000), 'draftInvoice' => fn () => $this->jobStatus()->draftInvoice($job)] as $what => $call) {
                try {
                    AuditContext::with($actor, $call);
                    $this->fail("{$actor} could {$what}");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringStartsWith('Only you can', $e->getMessage());
                }
            }
        }
        $this->assertSame('booked', $job->fresh()->status);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_job_that_finished_early_has_its_days_still_to_come_removed_as_you_then_is_done(): void
    {
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $job = $this->job(['starts_on' => '2026-06-08', 'ends_on' => '2026-06-12']);
        JobDay::query()->where('work_job_id', $job->id)->whereIn('on_date', ['2026-06-09', '2026-06-10', '2026-06-11'])->delete();

        try {
            $this->jobStatus()->markDone($job, ['2026-06-08']);
            $this->fail('A day that has been was removed');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('2026-06-08 isn’t one of Arena load-in’s days still to come.', $e->getMessage());
        }
        $this->assertSame('booked', $job->fresh()->status);

        $this->jobStatus()->markDone($job, ['2026-06-12']);

        $job->refresh();
        $this->assertSame(['done', ['2026-06-08'], '2026-06-08'], [$job->status, $job->workDates(), $job->ends_on->format('Y-m-d')]);
        $this->assertSame(['you', ['date' => '2026-06-12', 'kind' => 'work']], [AuditEvent::query()->where('action', 'job.day_removed')->sole()->actor, AuditEvent::query()->where('action', 'job.day_removed')->sole()->before]);
        $this->assertContains('job.done', $this->events($job));
    }

    public function test_back_to_booked_removes_only_an_untouched_draft_that_done_made(): void
    {
        // Untouched: removed with the job back to booked.
        $job = $this->job();
        $draft = $this->jobStatus()->markDone($job)['invoice'];
        $r = $this->jobStatus()->reopen($job);
        $this->assertSame(['booked', null], [$job->fresh()->status, $job->fresh()->done_at]);
        $this->assertSame(['removed', 'Back to booked.'], [$r['draft'], $r['message']]);
        $this->assertNull(Invoice::query()->find($draft->id));
        $this->assertSame(0, InvoiceLine::query()->where('invoice_id', $draft->id)->count());
        $this->assertSame($draft->id, AuditEvent::query()->where('action', 'job.reopened')->sole()->after['draft_removed']);
        $this->assertSame(['you', $draft->id], [AuditEvent::query()->where('action', 'invoice.deleted')->sole()->actor, (int) AuditEvent::query()->where('action', 'invoice.deleted')->sole()->subject_id]);

        // Edited by you: kept.
        $draft = $this->jobStatus()->markDone($job)['invoice'];
        app(InvoiceService::class)->updateDraft($draft, ['notes' => 'Payment due within 14 days.'], [['description' => 'Arena load-in', 'quantity' => '1', 'unit_price' => '700.00']]);
        $r = $this->jobStatus()->reopen($job);
        $this->assertSame(['kept', 'Back to booked. The draft stays: you’ve changed it.', $draft->id], [$r['draft'], $r['message'], $r['invoice']->id]);
        $this->assertSame('draft', $draft->fresh()->status);

        // A draft that was there before Done (reused, not made by it) always stays.
        $r = $this->jobStatus()->markDone($job);
        $this->assertSame('reused', $r['draft']);
        $this->assertNull($this->jobStatus()->reopen($job)['draft']);
        $this->assertSame('draft', $draft->fresh()->status);

        // "Got it" on a draft Done made counts as touching it.
        $other = $this->job(['title' => 'Arena show']);
        $made = $this->jobStatus()->markDone($other)['invoice'];
        $made->forceFill(['lines_seen_event_id' => AuditEvent::query()->max('id')])->save();
        $this->assertSame('kept', $this->jobStatus()->reopen($other)['draft']);
    }

    public function test_cancelling_stores_what_they_owe_and_is_allowed_with_an_issued_invoice(): void
    {
        $job = $this->job();
        $r = $this->jobStatus()->cancel($job, 'fee', 20000);
        $job->refresh();
        $this->assertSame(['cancelled', 'fee', 20000, null], [$job->status, $job->cancel_charge, $job->cancel_fee_pence, $job->done_at]);
        $this->assertSame(['made', 'Cancelled. Its invoice is ready to check.'], [$r['draft'], $r['message']]);
        $event = AuditEvent::query()->where('action', 'job.cancelled')->sole();
        $this->assertSame(['you', 'booked', 'cancelled', 'fee', 20000, $r['invoice']->id],
            [$event->actor, $event->before['status'], $event->after['status'], $event->after['cancel_charge'], $event->after['cancel_fee_pence'], $event->after['draft_made']]);

        $invoiced = $this->job(['title' => 'Arena show']);
        $this->issued($invoiced);
        $r = $this->jobStatus()->cancel($invoiced);
        $this->assertSame(['cancelled', 'none', 'Cancelled. INV-0041 stays: void it only if it’s wrong.'], [$invoiced->fresh()->status, $invoiced->fresh()->cancel_charge, $r['message']]);
        $this->assertSame('issued', Invoice::query()->where('number', 'INV-0041')->value('status'));

        foreach ([['fee', null, 'Give the cancellation fee'], ['refund', null, 'Say whether they owe you anything']] as [$charge, $fee, $message]) {
            try {
                $this->jobStatus()->cancel($this->job(['title' => 'Gala']), $charge, $fee);
                $this->fail("Cancelled with {$charge}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    public function test_undo_of_cancelled_restores_the_status_before_and_drops_its_untouched_draft(): void
    {
        $job = $this->job(['fee_pence' => null]);
        $this->jobStatus()->markDone($job);
        $doneAt = $job->fresh()->done_at->format('Y-m-d H:i:s');
        Invoice::query()->where('work_job_id', $job->id)->get()->each(fn (Invoice $i) => $i->delete()); // no draft to keep it simple

        $this->jobStatus()->cancel($job, 'costs');
        $made = Invoice::query()->where('work_job_id', $job->id)->sole();
        $r = $this->jobStatus()->undoCancel($job);

        $job->refresh();
        $this->assertSame(['done', $doneAt, null, null], [$job->status, $job->done_at->format('Y-m-d H:i:s'), $job->cancel_charge, $job->cancel_fee_pence]);
        $this->assertSame(['removed', 'Back to done.'], [$r['draft'], $r['message']]);
        $this->assertNull(Invoice::query()->find($made->id));
        $this->assertSame('Undo', AuditEvent::query()->where('action', 'job.uncancelled')->sole()->reason);

        $this->expectExceptionMessage('Arena load-in isn’t cancelled.');
        $this->jobStatus()->undoCancel($job);
    }

    public function test_the_job_form_status_goes_through_the_same_steps(): void
    {
        $job = $this->job();

        $this->assertSame('made', $this->jobStatus()->change($job, 'done')['draft'], 'Booked to done on an existing job gets the invoice ready');
        $this->assertNotNull($job->fresh()->done_at);
        $this->assertFalse($this->jobStatus()->change($job, 'done')['changed']);

        $this->jobStatus()->change($job, 'booked');
        $this->assertSame(['booked', null], [$job->fresh()->status, $job->fresh()->done_at], 'Done to booked clears when it was done');

        $this->jobStatus()->change($job, 'cancelled', 'fee', 15000);
        $this->assertSame(['cancelled', 'fee', 15000], [$job->fresh()->status, $job->fresh()->cancel_charge, $job->fresh()->cancel_fee_pence]);
        $this->assertFalse($this->jobStatus()->change($job, 'cancelled')['changed'], 'Cancelled again with nothing said keeps what they owe');

        try {
            $this->jobStatus()->change($job, 'done');
            $this->fail('A cancelled job was marked done from the form');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('set it back to booked', $e->getMessage());
        }
        $this->assertSame(['job.done', 'job.reopened', 'job.cancelled'], array_values(array_filter($this->events($job), fn ($a) => in_array($a, ['job.done', 'job.reopened', 'job.cancelled'], true))));

        $this->expectExceptionMessage('A job is booked, done or cancelled.');
        $this->jobStatus()->change($job, 'paid');
    }

    public function test_a_job_made_already_done_only_offers_draft_its_invoice(): void
    {
        $job = app(JobCreator::class)->create(['title' => 'Winter Expo', 'client_id' => $this->client->id, 'starts_on' => '2026-05-03', 'fee_pence' => 50000, 'status' => 'done'], 'you');

        $this->assertSame(['done', '2026-06-10 10:00'], [$job->status, $job->done_at->format('Y-m-d H:i')]);
        $this->assertSame(0, Invoice::query()->count(), 'Adding a job already done makes no draft');
        $this->assertTrue(JobStatus::offersDraft($job));

        $r = $this->jobStatus()->draftInvoice($job);
        $this->assertSame(['made', 'Its invoice is ready to check.'], [$r['draft'], $r['message']]);
        $this->assertFalse(JobStatus::offersDraft($job->fresh()));
        $this->assertSame('reused', $this->jobStatus()->draftInvoice($job)['draft']);
        $this->assertSame(1, Invoice::query()->count());

        $timesheet = Client::query()->create(['name' => 'Timesheet Crew', 'invoice_route' => 'none']);
        $this->assertFalse(JobStatus::offersDraft($this->job(['client_id' => $timesheet->id, 'status' => 'done'])), 'Not for a client who pays without an invoice');
        $this->assertFalse(JobStatus::offersDraft($this->job(['status' => 'booked'])));
    }

    public function test_a_locked_year_doesnt_stop_done_back_to_booked_or_cancelled(): void
    {
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();
        $job = $this->job(['starts_on' => '2026-03-03', 'ends_on' => '2026-03-03']);

        $this->jobStatus()->markDone($job);
        $this->jobStatus()->reopen($job);
        $this->jobStatus()->cancel($job, 'fee', 10000);

        $this->assertSame(['job.done', 'job.reopened', 'job.cancelled'], array_values(array_filter($this->events($job), fn ($a) => str_starts_with($a, 'job.') && $a !== 'job.created')));
    }
}
