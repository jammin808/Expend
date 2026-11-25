<?php

namespace Tests\Feature\Invoices;

use App\Domain\Gmail\GmailDrafts;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Jobs\PrepareInvoiceEmail;
use App\Mail\InvoiceEmail;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Putting an invoice in Gmail when Gmail is slow, and what you do meanwhile (FakeGmail, in memory):
 *  - the address you chose on the sheet, none included, is the one the draft has;
 *  - a try whose drafts.create reached Gmail without an answer never leaves a second draft, or one nobody tracks: a
 *    retry finds it (sent by you already: recorded as sent; edited by you: never taken for Expend's own), and once the
 *    invoice goes another way (emailed, marked sent) Expend's own draft is taken out;
 *  - a draft made while you marked the invoice sent, or for a reminder of an invoice paid meanwhile, isn't left open;
 *  - a conversation's malformed Message-ID is left out rather than stopping the draft;
 *  - a draft sent after you changed its subject is seen as sent; the mailer looks at Gmail before it refuses, and
 *    never chases an invoice still unsent in your Gmail drafts;
 *  - Issue and send checks the conversation before issuing.
 */
class InvoiceEmailTriesTest extends TestCase
{
    use InvoiceEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvoiceEmails();
        $this->travelTo('2026-06-05 10:00:00');
    }

    private function emails(): InvoiceEmails
    {
        return app(InvoiceEmails::class);
    }

    /**
     * issueToGmail's own contract: $to '' means "no address: you add it in Gmail". The client has a billing address,
     * but you chose none on the sheet: the draft must have no To (and so can't be one-tap sent from Telegram).
     */
    public function test_choosing_no_address_on_the_sheet_is_kept_when_the_client_has_one(): void
    {
        $result = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05', null, '');
        $row = $result['email'];
        $this->assertSame('drafted', $row->status);
        $this->assertArrayNotHasKey('To', $this->gmail->draft($row->gmail_draft_id)['headers'], 'You chose no address, but the draft is addressed to the client');
        $this->assertNull($row->to_address);
    }

    /**
     * The first try's drafts.create reached Gmail but its answer timed out ("Putting it in Gmail…"). You see the draft
     * in Gmail and press Send there. The queued retry must not then make a second invoice draft for you to send again.
     */
    public function test_a_retry_after_you_sent_the_first_tries_draft_from_gmail_makes_no_second_draft(): void
    {
        Queue::fake();
        $this->gmail->timeoutAfterCreate = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->assertSame('pending', InvoiceEmails::latest($invoice)->status);
        $this->assertCount(1, $this->gmail->drafts, 'Gmail made the draft although its answer timed out');

        $this->travelTo('2026-06-05 10:00:30');
        $this->gmail->sendByHand(array_key_first($this->gmail->drafts));
        $this->travelTo('2026-06-05 10:01:00');
        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());

        $this->assertCount(1, $this->gmail->sent());
        $this->assertSame([], $this->gmail->drafts, 'A second draft of an invoice already sent is waiting in Gmail to be sent again');
    }

    /**
     * Same first try (draft made in Gmail, answer timed out). While it is "Putting it in Gmail…" you press Email it from
     * Expend. The retry then sees it was sent and stops, but the first try's draft stays in your Gmail drafts, unknown
     * to Expend, ready to be sent a second time.
     */
    public function test_a_draft_left_by_a_timed_out_try_is_not_left_in_gmail_once_it_is_sent_another_way(): void
    {
        Queue::fake();
        Mail::fake();
        Setting::put('mail_method', 'server');
        $this->gmail->timeoutAfterCreate = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->assertSame('pending', InvoiceEmails::latest($invoice)->status);

        app(InvoiceMailer::class)->send($invoice->fresh());
        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());

        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertNull(InvoiceEmails::open($invoice));
        $this->assertSame([], $this->gmail->drafts, 'Expend’s own draft of an invoice already emailed is still in Gmail drafts, untracked');
    }

    /**
     * The first try's draft reached Gmail without an answer and Gmail stayed down for every retry: the row ends failed.
     * Gmail answering again, the draft is never hidden behind it: emailed from Expend, Expend's own draft is taken out
     * of Gmail; sent by you from Gmail meanwhile, it's recorded and not emailed again; and when the tries end with Gmail
     * answering, the draft is kept (adopted), not marked failed.
     */
    public function test_a_failed_row_never_hides_a_draft_that_is_in_gmail(): void
    {
        Queue::fake();
        Mail::fake();
        Setting::put('mail_method', 'server');
        $this->gmail->timeoutAfterCreate = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->down = true;
        $job = new PrepareInvoiceEmail($invoice->id);
        try {
            $job->handle($this->emails());
        } catch (\Throwable) {
        }
        $job->failed(null);
        $this->gmail->down = false;
        $this->assertSame('failed', InvoiceEmails::latest($invoice)->status);
        $this->assertCount(1, $this->gmail->drafts, 'The first try’s draft is in Gmail');

        $this->assertSame('Invoice INV-0001 is on its way to accounts@acme.example.test.', app(InvoiceMailer::class)->send($invoice->fresh()));
        $this->assertSame([], $this->gmail->drafts, 'Expend’s own draft is taken out once it’s emailed from Expend');

        // Sent by you from Gmail before you press Email it: recorded, not emailed again.
        $other = $this->emails()->issueToGmail($this->draftInvoice($this->job('Arena gala')), '2026-06-05');
        $row = $other['email'];
        $row->forceFill(['status' => 'failed', 'gmail_draft_id' => null, 'draft_message_id' => null, 'error' => 'Gmail didn’t answer'])->save();
        $this->gmail->sendByHand(array_key_first($this->gmail->drafts));
        $this->assertSame('Invoice INV-0002 was sent from your Gmail: it’s marked as sent.', app(InvoiceMailer::class)->send($other['invoice']->fresh(), once: true));
        $this->assertNotNull($other['invoice']->fresh()->sent_at);
        Mail::assertQueued(InvoiceEmail::class, 1);
    }

    public function test_tries_that_end_with_gmail_answering_keep_the_draft_gmail_made(): void
    {
        Queue::fake();
        $this->gmail->timeoutAfterCreate = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->assertSame('pending', InvoiceEmails::latest($invoice)->status);

        (new PrepareInvoiceEmail($invoice->id))->failed(null);

        $row = InvoiceEmails::latest($invoice);
        $this->assertSame(['drafted', array_key_first($this->gmail->drafts)], [$row->status, $row->gmail_draft_id]);
        $this->assertNotNull($row->draft_message_id, 'As made: Expend’s own');
    }

    /**
     * "I sent it myself" pressed while the retry is putting it in Gmail (the Gmail call in flight). The invoice is then
     * marked sent, yet the retry still records an open draft: Expend shows a sent invoice "In Gmail drafts, ready to
     * send" and the draft can be sent a second time. The record step re-checks the row's status, not the invoice's sent_at.
     */
    public function test_marked_sent_while_the_draft_is_being_made_leaves_no_open_draft(): void
    {
        Queue::fake();
        $this->gmail->down = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->assertSame('pending', InvoiceEmails::latest($invoice)->status);
        $this->gmail->down = false;

        $done = false;
        Http::fake(function (Request $request) use (&$done, $invoice) {
            if (! $done && $request->method() === 'POST' && str_ends_with((string) strtok($request->url(), '?'), '/drafts')) {
                $done = true;
                $this->emails()->markSentSelf($invoice->fresh(), 'accounts@acme.example.test');
            }

            return null; // the FakeGmail answers
        });
        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());

        $this->assertTrue($done);
        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertNull(InvoiceEmails::open($invoice), 'A sent invoice has an open Gmail draft, ready to be sent again');
        $this->assertSame([], $this->gmail->drafts);
    }

    /**
     * A malformed Message-ID anywhere in the booking conversation's References (here one without an "@", as some
     * ticketing systems write) makes the whole draft fail: the invoice can't go in its conversation, and you are shown
     * Symfony's own words. The conversation's ids are copied blindly into Symfony's strict id headers.
     */
    public function test_one_malformed_message_id_in_the_conversation_doesnt_stop_the_draft(): void
    {
        $job = $this->job();
        $this->gmail->messages[$this->po['id']]['headers']['References'] = '<4f1a2b3c.5d6e7f80> <older@acme.example.test>';
        $row = $this->emails()->issueToGmail($this->draftInvoice($job), '2026-06-05')['email'];

        $this->assertSame('drafted', $row->status, 'Not put in Gmail: '.$row->error);
    }

    /**
     * Owner's rule and ChaseLadder's own holds: "Make a reminder" while Gmail is slow leaves a pending reminder; the
     * invoice is then paid in full. The queued retry still writes a reminder ("I haven't received payment yet") into
     * Gmail, though make() would have refused it now. prepare() re-checks nothing about payment for a reminder.
     */
    public function test_a_reminder_retry_writes_nothing_once_the_invoice_is_paid(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $this->assertSame('sent', $this->emails()->check(InvoiceEmails::open($invoice)));
        $this->travelTo('2026-07-10 09:00:00');

        Queue::fake();
        $this->gmail->down = true;
        $made = app(ChaseLadder::class)->make($invoice->fresh());
        $this->assertSame('pending', $made['email']->status);

        $this->income(65000, $invoice->job);
        $this->gmail->down = false;
        (new PrepareInvoiceEmail($invoice->id, 'reminder'))->handle($this->emails());

        $this->assertNull(InvoiceEmails::open($invoice, 'reminder'), 'A reminder for a paid invoice is in Gmail drafts');
        $this->assertSame([], $this->gmail->drafts);
    }

    /**
     * The first try's draft is in Gmail (answer timed out); you open it there and change the words. The retry adopts
     * it by its X-Expend-Ref and records your edited version as Expend's own unedited draft. Taking it out later
     * (here: you void the invoice) then deletes your words, which Expend must never do.
     */
    public function test_a_draft_you_edited_before_the_retry_is_never_taken_for_expends_own(): void
    {
        Queue::fake();
        $this->gmail->timeoutAfterCreate = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $draftId = array_key_first($this->gmail->drafts);
        $this->gmail->edit($draftId, [], "Hi Sam,\n\nHere's the invoice, with the extra hour we agreed.\n");

        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());
        $this->assertSame('drafted', InvoiceEmails::latest($invoice)->status);

        app(InvoiceService::class)->void($invoice->fresh(), 'Wrong amount');
        $this->emails()->checkAll();
        $this->assertNotNull($this->gmail->draft($draftId), 'Your edited draft was deleted from Gmail as if it were Expend’s own');
    }

    /**
     * The other branch of the same window: if Gmail's editor drops Expend's X-Expend-Ref header when you edit the draft,
     * the retry finds nothing and makes a second invoice draft beside your edited one.
     */
    public function test_a_draft_you_edited_before_the_retry_is_not_doubled(): void
    {
        Queue::fake();
        $this->gmail->timeoutAfterCreate = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $draftId = array_key_first($this->gmail->drafts);
        $edited = $this->gmail->edit($draftId, [], "Hi Sam,\n\nHere's the invoice.\n");
        unset($this->gmail->messages[$edited]['headers']['X-Expend-Ref']);

        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());
        $this->assertCount(1, $this->gmail->drafts, 'Two invoice drafts for the same invoice are now in Gmail');
    }

    /**
     * Issue and send refuses a bad To before issuing; a bad conversation id is only checked after the invoice is issued,
     * so the press issues it and then says it isn't sent. Everything the draft needs from you should be checked first.
     */
    public function test_issue_and_send_checks_the_conversation_before_issuing(): void
    {
        $draft = $this->draftInvoice();
        try {
            $result = $this->emails()->issueAndSend($draft, 'accounts@acme.example.test', '2026-06-05', null, 'not a thread!');
            $message = $result['message'];
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('draft', $draft->fresh()->status, 'Issued, then: '.$message);
    }

    /**
     * You change the subject of Expend's draft in Gmail and press Send there. Gmail files a reply whose subject no
     * longer matches in a new conversation (simulated here by the sent message's threadId). check() only looks in the
     * draft's old conversation, so the invoice reads "Gmail draft deleted, not sent" though it went, and [Email it
     * from Expend] would send it again.
     */
    public function test_a_draft_sent_from_gmail_after_you_changed_its_subject_is_seen_as_sent(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $row = InvoiceEmails::open($invoice);
        $this->gmail->edit($row->gmail_draft_id, ['Subject' => 'Invoice for the Arena load-in']);
        $this->travelTo('2026-06-05 11:00:00');
        $sent = $this->gmail->sendByHand($row->gmail_draft_id);
        $this->gmail->messages[$sent['id']]['threadId'] = 't-new-conversation';

        $this->emails()->checkAll();
        $this->assertSame('sent', $row->fresh()->status, 'Sent from Gmail, but recorded as deleted unsent');
        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    /**
     * You delete Expend's draft in Gmail (as the mailer's own refusal tells you to), then press Email it from Expend. The
     * mailer refuses from its stored row only ("is in your Gmail drafts … delete it there first") until some check
     * happens to run; nothing schedules one yet, and later() does nothing on a sync queue.
     */
    public function test_the_mailer_sees_a_draft_you_deleted_in_gmail(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->deleteByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $this->travelTo('2026-06-06 09:00:00');

        try {
            $message = app(InvoiceMailer::class)->send($invoice->fresh());
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('Invoice INV-0001 is on its way to accounts@acme.example.test.', $message);
    }

    /**
     * The invoice is still unsent in your Gmail drafts when it falls overdue (you forgot to press Send). The overdue
     * nudge's [Send a reminder] (the mailer, once) emails the client a reminder that payment hasn't come, for an invoice
     * they never received. ChaseLadder refuses this ("hasn't been sent yet: send it first"); the mailer only checks for an
     * open reminder draft, not the open invoice draft.
     */
    public function test_the_mailer_sends_no_reminder_while_the_invoice_itself_is_unsent_in_gmail(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->assertSame('drafted', InvoiceEmails::open($invoice)->status);
        $this->travelTo('2026-07-20 09:00:00');

        $message = app(InvoiceMailer::class)->send($invoice->fresh(), reminder: true, once: true);
        Mail::assertNothingQueued();
        $this->assertNull($invoice->fresh()->reminded_at, 'Reminder emailed: '.$message);
    }

    /** Sanity: non-ASCII names and subjects, a long References chain, the PDF byte for byte, one To and no Cc. */
    public function test_sanity_non_ascii_and_threading_headers(): void
    {
        Setting::put('business_name', 'Zoë Café Sound, Lighting & Co');
        $job = $this->job();
        $refs = implode(' ', array_map(fn ($i) => '<r'.$i.'@acme.example.test>', range(1, 25)));
        $this->gmail->messages[$this->po['id']]['headers']['Subject'] = 'Bestellung für Zoë – PO 4471 · Arena';
        $this->gmail->messages[$this->po['id']]['headers']['References'] = $refs;
        $row = $this->emails()->issueToGmail($this->draftInvoice($job), '2026-06-05')['email'];
        $draft = $this->gmail->draft($row->gmail_draft_id);
        $parsed = (new MailMimeParser)->parse($draft['raw'], false);

        $this->assertSame('Re: Bestellung für Zoë – PO 4471 · Arena', $draft['headers']['Subject']);
        $this->assertSame('Zoë Café Sound, Lighting & Co', $parsed->getHeader('From')->getPersonName());
        $this->assertSame('alex@example.test', $parsed->getHeader('From')->getEmail());
        $this->assertSame('<'.$this->po['message_id'].'>', $draft['headers']['In-Reply-To']);
        $ids = GmailDrafts::ids($draft['headers']['References']);
        $this->assertCount(20, $ids);
        $this->assertSame($this->po['message_id'], end($ids));
        $this->assertSame(['accounts@acme.example.test'], array_map(fn ($a) => $a->getEmail(), $parsed->getHeader('To')->getAddresses()));
        $this->assertNull($parsed->getHeader('Cc'));
        $this->assertNull($parsed->getHeader('Bcc'));
        $invoice = Invoice::query()->sole();
        $this->assertSame($invoice->pdf_sha256, hash('sha256', $draft['files']['INV-0001.pdf']));
        $this->assertStringContainsString('3–4 Jun 2026', $draft['text']);
        $this->assertSame($this->po['thread'], $this->gmail->created[0]['message']['threadId']);
    }

    /** Sanity: the happy path of Issue and send threads the sent invoice to the PO and sends exactly once. */
    public function test_sanity_issue_and_send_once(): void
    {
        $result = $this->emails()->issueAndSend($this->draftInvoice(), 'accounts@acme.example.test', '2026-06-05');
        $this->assertTrue($result['sent']);
        $this->assertCount(1, $this->gmail->sent());
        $sent = $this->gmail->sent()[0];
        $this->assertSame('<'.$this->po['message_id'].'>', $sent['headers']['In-Reply-To']);
        $this->assertSame(Invoice::query()->sole()->id.'/invoice/'.substr(Invoice::query()->sole()->pdf_sha256, 0, 8), $sent['headers']['X-Expend-Ref']);
        // Pressed again: refused, nothing more sent.
        try {
            $this->emails()->send($result['invoice'], (string) $result['email']->draft_message_id);
        } catch (InvalidArgumentException) {
        }
        $this->assertCount(1, $this->gmail->sent());
    }
}
