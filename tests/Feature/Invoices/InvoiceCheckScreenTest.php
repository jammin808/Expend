<?php

namespace Tests\Feature\Invoices;

use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\InvoiceLine;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Build slice S9 (the invoice's HTTP): a draft's check screen (brought up to date, every line with its source and
 * version, no Gmail call and no token in the props), per-line presses and "Got it" tied to what you saw (409 with the
 * sheet when it changed), issuing tied to the sheet, Issue and put in Gmail drafts, Issue and send, Send now, I sent it
 * myself, "Make a reminder" only when you press it, "They've paid", and deleting a draft with every line.
 */
class InvoiceCheckScreenTest extends TestCase
{
    use InvoiceEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvoiceEmails();
        $this->travelTo('2026-06-05 10:00:00');
    }

    private function props(Invoice $invoice): array
    {
        $page = $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('invoices/show', false))->viewData('page');

        return json_decode(json_encode($page['props']), true);
    }

    /** Sent the way an Inertia page sends it: JSON, back to the page. */
    private function visit(string $method, string $uri, array $body, Invoice $invoice): TestResponse
    {
        return $this->from("/invoices/{$invoice->id}")->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** The issue sheet's binding, as the page loaded it. */
    private function shown(array $props): array
    {
        return ['lines_version' => $props['invoice']['lines_version'], 'seen_up_to' => $props['invoice']['seen_up_to']];
    }

    public function test_the_check_screen_has_every_line_with_its_source_and_no_gmail_call_or_token(): void
    {
        $draft = $this->draftInvoice();
        $fee = InvoiceLine::query()->where('invoice_id', $draft->id)->sole();
        $fee->forceFill(['included' => false, 'ticked_by' => 'you'])->save();
        Http::fake(fn () => throw new \RuntimeException('No call to Gmail on the page.'));

        $props = $this->props($draft);
        $line = $props['invoice']['lines'][0];
        $this->assertSame([$fee->id, InvoiceDraftSync::version($fee->fresh()), false, 'fee', 'job:'.$draft->work_job_id.'#fee', 'you'],
            [$line['id'], $line['version'], $line['included'], $line['source'], $line['source_ref'], $line['ticked_by']]);
        $this->assertSame(InvoiceDraftSync::linesVersion($draft), $props['invoice']['lines_version']);
        $this->assertSame(['accounts@acme.example.test'], $props['draft']['addresses']);
        $this->assertSame($this->po['thread'], $props['draft']['threads'][0]['thread_id']);
        $this->assertTrue($props['draft']['gmail']);
        $this->assertArrayHasKey('blocks', $props['draft']['checks']);
        $json = json_encode($props);
        // The Gmail tokens (FakeGmail's 'rt' and 'at') are never a value in the props.
        $this->assertStringNotContainsString(':"rt"', $json);
        $this->assertStringNotContainsString(':"at"', $json);
        $this->assertStringNotContainsString('token', $json);

        // A second look at an unchanged draft writes nothing.
        $events = AuditEvent::query()->count();
        $this->props($draft);
        $this->assertSame($events, AuditEvent::query()->count());
    }

    public function test_a_line_press_is_tied_to_its_version_and_a_stale_one_gets_the_sheet_again(): void
    {
        $draft = $this->draftInvoice();
        $props = $this->props($draft);
        $line = $props['invoice']['lines'][0];

        $this->patchJson("/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'untick', 'version' => $line['version']])->assertOk()
            ->assertJsonPath('message', 'Left off the invoice.');
        $this->assertFalse((bool) InvoiceLine::query()->find($line['id'])->included);

        // The version it carried is old now: nothing is done, and the sheet comes back as it is.
        $stale = $this->patchJson("/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'tick', 'version' => $line['version']])->assertStatus(409)->json();
        $this->assertSame('“'.$line['description'].'” changed since you looked: check it and try again.', $stale['message']);
        $this->assertFalse($stale['sheet']['lines'][0]['included']);
        $this->assertFalse((bool) InvoiceLine::query()->find($line['id'])->included);
        // From the page: back with the reason.
        $this->visit('PATCH', "/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'tick', 'version' => $line['version']], $draft)
            ->assertSessionHas('error', '“'.$line['description'].'” changed since you looked: check it and try again.');
        $this->patchJson("/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'remove'])->assertStatus(422)
            ->assertJsonPath('message', '“'.$line['description'].'” comes from the job: untick it instead.');
        $this->patchJson("/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'explode'])->assertStatus(422)->assertJsonValidationErrors(['action' => 'Say what to do with the line.']);
    }

    public function test_a_save_with_a_stale_line_version_is_refused_and_got_it_marks_what_was_shown(): void
    {
        $draft = $this->draftInvoice();
        $props = $this->props($draft);
        $line = $props['invoice']['lines'][0];
        $form = ['currency' => 'GBP', 'bill_to_name' => 'Acme Events', 'lines_version' => $props['invoice']['lines_version'],
            'lines' => [['id' => $line['id'], 'version' => $line['version'], 'description' => 'Arena load-in, two days', 'quantity' => '1', 'unit_price' => '650.00', 'included' => true]]];
        $this->patchJson("/invoices/{$draft->id}", $form)->assertOk()->assertJsonPath('message', 'Draft saved.');
        $this->assertTrue((bool) InvoiceLine::query()->find($line['id'])->own_text);

        $stale = $this->patchJson("/invoices/{$draft->id}", $form)->assertStatus(409)->json();
        $this->assertSame('The draft’s lines changed since you opened it: check them and save again.', $stale['message']);
        $this->assertSame(InvoiceDraftSync::linesVersion($draft), $stale['sheet']['lines_version']);

        // The job's fee changes: the sync follows it (your wording stays), a change since you last looked; Got it marks it seen.
        WorkJob::query()->find($draft->work_job_id)->forceFill(['fee_pence' => 70000])->save();
        $props = $this->props($draft);
        $this->assertCount(1, $props['draft']['changes']);
        $this->assertSame('invoice.line_changed', $props['draft']['changes'][0]['action']);
        $this->assertSame(['Arena load-in, two days', 70000], [$props['invoice']['lines'][0]['description'], $props['invoice']['lines'][0]['amount_minor']]);
        $this->postJson("/invoices/{$draft->id}/seen", ['up_to' => $props['invoice']['seen_up_to']])->assertOk()->assertJsonPath('message', 'Got it.');
        $this->assertSame([], $this->props($draft)['draft']['changes']);
        $this->postJson("/invoices/{$draft->id}/seen", [])->assertStatus(422)->assertJsonValidationErrors(['up_to' => 'Open the draft again: there’s nothing to mark as seen.']);
    }

    public function test_issuing_is_tied_to_the_sheet_and_put_in_gmail_drafts_from_it(): void
    {
        $draft = $this->draftInvoice();
        $props = $this->props($draft);
        $shown = $this->shown($props);
        $line = $props['invoice']['lines'][0];
        // The lines changed after the sheet opened: refused, with the sheet again.
        $this->patchJson("/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'untick', 'version' => $line['version']])->assertOk();
        $this->patchJson("/invoices/{$draft->id}/lines/{$line['id']}", ['action' => 'tick', 'version' => InvoiceDraftSync::version(InvoiceLine::query()->find($line['id']))])->assertOk();
        $stale = $this->postJson("/invoices/{$draft->id}/issue", $shown)->assertStatus(409)->json();
        $this->assertSame('The lines changed since you opened the issue sheet: check them and issue again.', $stale['message']);
        $this->assertSame('draft', $draft->fresh()->status);

        // Issue and put in Gmail drafts, from the sheet as it is now.
        $this->visit('POST', "/invoices/{$draft->id}/gmail", $this->shown($this->props($draft)) + ['issue_date' => '2026-06-05'], $draft)
            ->assertSessionHas('success', 'INV-0001 issued. The email is in your Gmail drafts, ready to send.');
        $invoice = $draft->fresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('drafted', InvoiceEmails::latest($invoice)->status);

        // The issued view says where the email is; Send now reads the draft, then sends exactly that version.
        $issued = $this->props($invoice)['issued'];
        $this->assertSame(['drafted', 'accounts@acme.example.test'], [$issued['email']['status'], $issued['email']['to']]);
        $this->assertArrayNotHasKey('gmail_draft_id', $issued['email']);
        $preview = $this->getJson("/invoices/{$invoice->id}/gmail/preview")->assertOk()->json();
        $this->assertSame('accounts@acme.example.test', $preview['to']);
        $this->postJson("/invoices/{$invoice->id}/gmail/send", ['version' => 'not-it'])->assertStatus(409)
            ->assertJsonPath('message', 'You’ve changed it in Gmail since: send it there, or look again.');
        $this->postJson("/invoices/{$invoice->id}/gmail/send", ['version' => $preview['version']])->assertOk()
            ->assertJsonPath('message', 'Invoice INV-0001 is on its way to accounts@acme.example.test.');
        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    public function test_issue_only_says_when_a_waiting_payment_looks_like_its_for_it(): void
    {
        $draft = $this->draftInvoice();
        $account = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $account->id, 'original_filename' => 'june.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x', 'status' => 'committed']);
        BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-05', 'tax_year' => '2026-27',
            'description' => 'ACME EVENTS', 'counterparty' => 'ACME EVENTS', 'merchant_key' => 'ACME EVENTS', 'amount_pence' => 65000, 'raw' => [], 'fingerprint' => 'waiting-650']);

        $this->visit('POST', "/invoices/{$draft->id}/issue", $this->shown($this->props($draft)) + ['issue_date' => '2026-06-05'], $draft)
            ->assertSessionHas('success', 'Issued INV-0001. 1 waiting payment looks like it’s for it. Put it in Gmail or send it from here.');
        $this->assertSame('issued', $draft->fresh()->status);
    }

    public function test_issue_and_send_in_one_press_checks_the_total_it_showed(): void
    {
        $draft = $this->draftInvoice();
        $shown = $this->shown($this->props($draft));
        $this->postJson("/invoices/{$draft->id}/issue-and-send", $shown + ['to' => 'accounts@acme.example.test', 'total' => 1])->assertStatus(409)
            ->assertJsonPath('message', 'The total changed since you looked: check it and issue again.');
        $this->postJson("/invoices/{$draft->id}/issue-and-send", $shown + ['to' => 'accounts@acme'])->assertStatus(422)
            ->assertJsonValidationErrors(['to' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.']);

        $this->postJson("/invoices/{$draft->id}/issue-and-send", $shown + ['to' => 'accounts@acme.example.test', 'total' => (int) $draft->fresh()->total_minor])->assertOk()
            ->assertJsonPath('sent', true);
        $this->assertNotNull($draft->fresh()->sent_at);
        $this->assertSame(1, count($this->gmail->sent()));
    }

    public function test_a_reminder_is_made_only_when_you_press_make_a_reminder(): void
    {
        $emails = app(InvoiceEmails::class);
        $invoice = $emails->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $emails->check(InvoiceEmails::open($invoice));
        $this->travelTo('2026-07-08 09:00:00');

        // Opening the page of an overdue invoice writes no reminder.
        $issued = $this->props($invoice->fresh())['issued'];
        $this->assertTrue($issued['can_remind']);
        $this->assertSame(0, InvoiceEmail::query()->where('kind', 'reminder')->count());

        $this->visit('POST', "/invoices/{$invoice->id}/reminder", [], $invoice)
            ->assertSessionHas('success', 'A reminder for INV-0001 (3 days overdue) is in your Gmail drafts, ready to send.');
        $this->assertSame(1, InvoiceEmail::query()->where('kind', 'reminder')->where('status', 'drafted')->count());
        $this->assertSame('you', AuditEvent::query()->where('action', 'invoice.reminder_drafted')->sole()->actor);

        // "They've paid": the overdue item waits a week, and Expend's own unedited reminder comes out of Gmail.
        $json = $this->postJson("/invoices/{$invoice->id}/paid-wait")->assertOk()->json();
        $this->assertStringStartsWith('OK: Expend won’t ask about INV-0001 for a week.', $json['message']);
        $this->assertTrue(TodoDismissal::query()->where('key', 'invoice_overdue:'.$invoice->id)->whereNotNull('until')->exists());
        $this->assertNull(InvoiceEmails::open($invoice, 'reminder'));
    }

    public function test_i_sent_it_myself_and_deleting_a_draft_takes_every_line_with_it(): void
    {
        $invoice = app(InvoiceEmails::class)->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['to' => 'accounts@acme.example.test'], $invoice)->assertSessionHas('success');
        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'invoice.marked_sent')->count());

        // A follow-up draft for the same job, one line unticked: delete takes them all, audited.
        $job = WorkJob::query()->find($invoice->work_job_id);
        $draft = Invoice::query()->create(['status' => 'draft', 'work_job_id' => $job->id, 'client_id' => $job->client_id, 'currency' => 'GBP', 'bill_to_name' => 'Acme Events']);
        $draft->allLines()->create(['description' => 'Typed', 'quantity' => '1', 'unit_price_minor' => 1000, 'amount_minor' => 1000, 'sort' => 0]);
        $draft->allLines()->create(['description' => 'Unticked', 'quantity' => '1', 'unit_price_minor' => 500, 'amount_minor' => 500, 'sort' => 1, 'included' => false]);
        $this->visit('DELETE', "/invoices/{$draft->id}", ['another_way' => true], $draft)->assertRedirect("/jobs/{$job->id}");
        $this->assertNull(Invoice::query()->find($draft->id));
        $this->assertSame(0, InvoiceLine::query()->where('invoice_id', $draft->id)->count());
        $deleted = AuditEvent::query()->where('action', 'invoice.deleted')->sole();
        $this->assertCount(2, $deleted->before['lines']);
        $this->assertSame('You invoiced it another way', $deleted->reason);
        $this->assertSame('Already sorted', TodoDismissal::query()->where('key', 'invoice:'.$job->id)->value('note'));
        $this->visit('DELETE', "/invoices/{$invoice->id}", [], $invoice)->assertSessionHas('error', 'Only drafts can be deleted. Void an issued invoice instead.');
    }

    public function test_the_invoice_list_makes_the_same_number_of_queries_for_many_invoices(): void
    {
        $client = $this->client();
        $make = function (int $from, int $n) use ($client) {
            for ($i = $from; $i < $from + $n; $i++) {
                $job = WorkJob::query()->create(['title' => 'Show '.$i, 'client_id' => $client->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
                Invoice::query()->create(['status' => 'issued', 'number' => 'INV-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'client_id' => $client->id, 'work_job_id' => $job->id,
                    'currency' => 'GBP', 'total_minor' => 10000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-02', 'due_date' => '2026-06-01']);
            }
        };
        $count = function () {
            $this->get('/invoices')->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/invoices')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('invoices/index', false)->where('invoices.0.state', 'unpaid'));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $make(1, 3);
        $few = $count();
        $make(4, 40);
        $this->assertSame($few, $count());
    }

    public function test_a_share_typed_the_wrong_way_is_refused_in_words(): void
    {
        $job = $this->job('Arena load-in', [], false);
        $other = WorkJob::query()->create(['title' => 'Other show', 'client_id' => $job->client_id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'status' => 'done']);
        $account = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $account->id, 'original_filename' => 'june.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-03', 'tax_year' => '2026-27',
            'description' => 'TRAINLINE', 'counterparty' => 'Trainline', 'merchant_key' => 'TRAINLINE', 'amount_pence' => -8450, 'raw' => [], 'fingerprint' => 'share-typo']);
        app(AllocationWriter::class)->decide($tx, [['kind' => 'business_expense', 'category_id' => (int) Category::query()->where('code', 'travel_rail')->value('id'),
            'amount_pence' => -8450, 'work_job_id' => $job->id]]);
        $draft = $this->draftInvoice($job);
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', 'tx:'.$tx->id.'#j'.$job->id)->sole();
        $events = AuditEvent::query()->count();

        $this->from("/invoices/{$draft->id}")->post("/invoices/{$draft->id}/share", ['line' => $line->id, 'job_id' => $other->id, 'amount' => '42,25'])
            ->assertRedirect("/invoices/{$draft->id}")->assertSessionHas('error', 'Give the other job’s share as an amount, like 42.25.');
        $this->postJson("/invoices/{$draft->id}/share", ['line' => $line->id, 'job_id' => $other->id, 'amount' => '42.255'])
            ->assertStatus(422)->assertJsonPath('message', 'Give the other job’s share as an amount, like 42.25.');
        $this->assertSame($events, AuditEvent::query()->count());
    }

    public function test_a_void_invoice_is_never_put_in_gmail_and_a_failed_try_reads_as_an_error(): void
    {
        $invoice = $this->issuedInvoice();
        app(InvoiceService::class)->void($invoice, 'Wrong client');
        $events = AuditEvent::query()->count();
        $this->visit('POST', "/invoices/{$invoice->id}/gmail", [], $invoice)->assertSessionHas('error', 'Only an issued invoice can go in Gmail.');
        $this->postJson("/invoices/{$invoice->id}/gmail")->assertStatus(422)->assertJsonPath('message', 'Only an issued invoice can go in Gmail.');
        $this->assertSame([0, $events], [InvoiceEmail::query()->count(), AuditEvent::query()->count()]);

        // "Try again" on an issued one Gmail won't take: said as an error, not a success.
        $issued = $this->issuedInvoice($this->job('Second show', [], false));
        $this->gmail->status = 400;
        $this->visit('POST', "/invoices/{$issued->id}/gmail", [], $issued)->assertSessionMissing('success')->assertSessionHas('error');
    }

    public function test_the_job_pages_draft_invoice_button_agrees_with_draft_its_invoice(): void
    {
        $job = $this->job('Called-off show', ['status' => 'cancelled', 'cancel_charge' => 'none'], false);
        $this->from("/jobs/{$job->id}")->post("/jobs/{$job->id}/invoice")
            ->assertRedirect("/jobs/{$job->id}")->assertSessionHas('error', 'Called-off show was cancelled with nothing owed: there’s nothing to invoice.');
        $this->assertSame(0, Invoice::query()->where('work_job_id', $job->id)->count());

        // A job already invoiced still gets a follow-up draft; the open draft is reused.
        $issued = $this->issuedInvoice();
        $this->post("/jobs/{$issued->work_job_id}/invoice")->assertRedirect();
        $followUp = Invoice::query()->where('work_job_id', $issued->work_job_id)->where('status', 'draft')->sole();
        $this->post("/jobs/{$issued->work_job_id}/invoice")->assertRedirect("/invoices/{$followUp->id}");
        $this->assertSame(1, Invoice::query()->where('work_job_id', $issued->work_job_id)->where('status', 'draft')->count());
    }

    public function test_an_issued_invoice_never_sent_isnt_overdue_on_the_invoice_list(): void
    {
        $this->travelTo('2026-07-10 10:00:00');
        $client = $this->client();
        $make = fn (string $number, ?string $sent, string $issued = '2026-05-02', string $due = '2026-06-01') => Invoice::query()->create(['status' => 'issued', 'number' => $number,
            'client_id' => $client->id, 'currency' => 'GBP', 'total_minor' => 50000, 'bill_to_name' => 'Acme Events', 'issue_date' => $issued, 'due_date' => $due, 'sent_at' => $sent]);
        $make('INV-0001', null);
        $make('INV-0002', '2026-05-02 09:00:00');
        // Sent late (1 July, 30 days' terms): due from 31 July, so not overdue yet.
        $make('INV-0003', '2026-07-01 09:00:00', '2026-06-05', '2026-07-05');

        $page = $this->get('/invoices')->assertOk()->viewData('page');
        $rows = collect(json_decode(json_encode($page['props']), true)['invoices'])->keyBy('number');
        $this->assertSame([false, null], [$rows['INV-0001']['overdue'], $rows['INV-0001']['overdue_days']]);
        $this->assertSame([true, 39], [$rows['INV-0002']['overdue'], $rows['INV-0002']['overdue_days']]);
        $this->assertSame([false, null], [$rows['INV-0003']['overdue'], $rows['INV-0003']['overdue_days']]);
    }
}
