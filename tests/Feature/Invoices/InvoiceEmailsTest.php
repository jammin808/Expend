<?php

namespace Tests\Feature\Invoices;

use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobStage;
use App\Jobs\CheckInvoiceEmails;
use App\Jobs\PrepareInvoiceEmail;
use App\Mail\InvoiceEmail as InvoiceMail;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\InvoiceLine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S7: an issued invoice put in your Gmail drafts in the booking's conversation, sent only by your press,
 * and seen when it goes. Gmail is the in-memory FakeGmail: nothing here calls Google.
 */
class InvoiceEmailsTest extends TestCase
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

    private function refusal(callable $do): ?string
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function audit(string $action): ?AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->first();
    }

    public function test_issue_and_put_in_gmail_drafts_replies_in_the_po_conversation(): void
    {
        $job = $this->job();
        $result = $this->emails()->issueToGmail($this->draftInvoice($job), '2026-06-05');
        $invoice = $result['invoice'];

        $this->assertSame('INV-0001 issued. The email is in your Gmail drafts, ready to send.', $result['message']);
        $row = $result['email'];
        $this->assertSame(['invoice', 'gmail', 'drafted', 'accounts@acme.example.test', $this->po['thread'], 'Re: PO 4471 for Arena load-in'],
            [$row->kind, $row->route, $row->status, $row->to_address, $row->gmail_thread_id, $row->subject]);
        $this->assertSame($row->draft_message_id, $row->current_message_id);

        // The draft in Gmail: in the PO's conversation, replying to its last message, To only, the issued PDF attached.
        $this->assertCount(1, $this->gmail->drafts);
        $this->assertSame($this->po['thread'], $this->gmail->created[0]['message']['threadId']);
        $draft = $this->gmail->draft($row->gmail_draft_id);
        $this->assertSame('<'.$this->po['message_id'].'>', $draft['headers']['In-Reply-To']);
        $this->assertSame('<'.$this->po['message_id'].'>', $draft['headers']['References']);
        $this->assertSame('accounts@acme.example.test', $draft['headers']['To']);
        $this->assertArrayNotHasKey('Cc', $draft['headers']);
        $this->assertSame($invoice->id.'/invoice/'.substr($invoice->pdf_sha256, 0, 8), $draft['headers']['X-Expend-Ref']);
        $this->assertSame(['INV-0001.pdf'], array_keys($draft['files']));
        $this->assertSame($invoice->pdf_sha256, hash('sha256', $draft['files']['INV-0001.pdf']));

        // Audited as yours; the job's chip says where it is.
        $drafted = $this->audit('invoice.gmail_drafted');
        $this->assertSame('you', $drafted->actor);
        $this->assertSame(['number' => 'INV-0001', 'to' => 'accounts@acme.example.test', 'subject' => 'Re: PO 4471 for Arena load-in', 'thread' => $this->po['thread'],
            'draft' => $row->gmail_draft_id], $drafted->after);
        $this->assertSame('In Gmail drafts', JobStage::of([$job])[$job->id]['chip']);
        $this->assertNull($invoice->fresh()->sent_at, 'Nothing is sent until you press Send');
    }

    public function test_the_email_stands_on_its_own_with_costs_by_kind_and_no_card_digits_or_postcodes(): void
    {
        $job = $this->job('Arena load-in LS1 4AP card 4929 1234 5678 9012');
        $invoice = $this->issuedInvoice($job);
        // A train, a hotel and mileage, as the draft sync writes them (added straight to the table: the invoice is issued).
        foreach ([['cost', 'Train: Trainline, London to Leeds, 3 Jun 2026 (at cost)', 4500], ['cost', 'Hotel: Premier Inn, Leeds, 3 Jun 2026 (at cost)', 12000],
            ['cost', 'Train: Trainline, Leeds to London, 4 Jun 2026 (at cost)', 4500], ['mileage', 'Mileage 3 Jun: Leeds Arena and back, 142.5 miles at 45p', 6413]] as $i => [$source, $text, $pence]) {
            DB::table('invoice_lines')->insert(['invoice_id' => $invoice->id, 'description' => $text, 'quantity' => 1, 'unit_price_minor' => $pence, 'amount_minor' => $pence,
                'sort' => 10 + $i, 'source' => $source, 'included' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $text = InvoiceEmails::text($invoice->fresh());
        $this->assertSame("Hello,\n\nPlease find attached invoice INV-0001 for Arena load-in card, 3–4 Jun 2026 (PO 4471).\n\n"
            ."The amount is £650.00, due by 5 July 2026.\n\n"
            ."It includes travel and expenses at cost: train £90.00, hotel £120.00, mileage £64.13. Receipts are available if you need them.\n\n"
            ."My bank details are on the invoice.\n\nMany thanks,\nAlex Smith Sound\n", $text);
        $this->assertStringNotContainsString('LS1', $text);
        $this->assertStringNotContainsString('4929', $text);
        $this->assertStringNotContainsString('00-00-00', $text, 'Bank details stay on the invoice');
        $this->assertSame('Invoice INV-0001 · Arena load-in card · PO 4471', InvoiceEmails::subjectFor($invoice));
    }

    public function test_a_client_set_to_new_email_gets_a_new_email_and_re_is_never_doubled(): void
    {
        $this->client(['invoice_route' => 'new']);
        $result = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05');
        $row = $result['email'];
        $this->assertNull($this->gmail->created[0]['message']['threadId'] ?? null);
        $this->assertSame('Invoice INV-0001 · Arena load-in · PO 4471', $row->subject);
        $this->assertArrayNotHasKey('In-Reply-To', $this->gmail->draft($row->gmail_draft_id)['headers']);
        $this->assertSame(['new_email' => true], array_intersect_key($this->audit('invoice.gmail_drafted')->after, ['new_email' => 1]));

        $this->assertSame('RE: PO 4471', InvoiceEmails::re('RE: PO 4471'));
        $this->assertSame('Re: PO 4471', InvoiceEmails::re('PO 4471'));
        $this->assertSame('re:PO 4471', InvoiceEmails::re('re:PO 4471'));
    }

    public function test_no_address_puts_it_in_gmail_for_you_to_add_one(): void
    {
        $this->client()->forceFill(['billing_email' => null])->save();
        $result = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05', null, '');
        $this->assertSame('drafted', $result['email']->status);
        $this->assertNull($result['email']->to_address);
        $this->assertArrayNotHasKey('To', $this->gmail->draft($result['email']->gmail_draft_id)['headers']);
        $this->assertSame('That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.',
            $this->refusal(fn () => $this->emails()->issueToGmail($this->draftInvoice($this->job('Gala dinner')), '2026-06-05', null, 'a@b.example, c@d.example')));
    }

    public function test_prepare_is_idempotent_a_timeout_after_gmail_made_the_draft_is_adopted_by_the_retry(): void
    {
        Queue::fake();
        $this->gmail->timeoutAfterCreate = true;
        $result = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05');
        $invoice = $result['invoice'];

        // Gmail made the draft but didn't answer in time: the invoice is issued, it's being put in Gmail, and the queue tries again.
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('pending', $result['email']->status);
        $this->assertSame('INV-0001 issued. Putting it in Gmail… Gmail didn’t answer, so Expend will try again in a minute.', $result['message']);
        $this->assertSame('Putting it in Gmail…', JobStage::of([$invoice->job])[$invoice->work_job_id]['chip']);
        Queue::assertPushed(PrepareInvoiceEmail::class, fn (PrepareInvoiceEmail $j) => $j->invoiceId === $invoice->id && $j->kind === 'invoice' && $j->tries === 3);
        $this->assertCount(1, $this->gmail->drafts);

        // The retry finds that draft by its X-Expend-Ref and adopts it: still only one draft.
        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());
        $row = InvoiceEmails::latest($invoice);
        $this->assertSame('drafted', $row->status);
        $this->assertCount(1, $this->gmail->drafts);
        $this->assertSame(array_key_first($this->gmail->drafts), $row->gmail_draft_id);
        $this->assertTrue($this->audit('invoice.gmail_drafted')->after['adopted']);

        // Asked again: the same row, nothing new in Gmail.
        $this->assertSame($row->id, $this->emails()->prepare($invoice, 'invoice', [])->id);
        $this->assertCount(1, $this->gmail->drafts);
        $this->assertSame(1, InvoiceEmail::query()->count());
    }

    public function test_when_every_try_fails_the_row_says_so_and_the_invoice_stays_issued(): void
    {
        Queue::fake();
        $this->gmail->down = true;
        $result = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05');
        $invoice = $result['invoice'];
        $this->assertSame('pending', $result['email']->status);

        $job = new PrepareInvoiceEmail($invoice->id);
        try {
            $job->handle($this->emails());
            $this->fail('A try Gmail doesn’t answer is thrown, for the queue to try again');
        } catch (\Throwable $e) {
            $this->assertTrue(InvoiceEmails::transient($e));
        }
        $job->failed(null);
        $row = InvoiceEmails::latest($invoice);
        $this->assertSame(['failed', 'Gmail didn’t answer'], [$row->status, $row->error]);
        $failed = $this->audit('invoice.gmail_failed');
        $this->assertSame(['system', 'It isn’t in your Gmail drafts'], [$failed->actor, $failed->reason]);
        $this->assertSame('Not sent', JobStage::of([$invoice->job])[$invoice->work_job_id]['chip']);

        // Try again works once Gmail answers.
        $this->gmail->down = false;
        $this->assertSame('drafted', $this->emails()->prepareOrRetry($invoice, 'invoice', [])->status);
        $this->assertCount(1, $this->gmail->drafts);
    }

    public function test_a_retry_after_it_was_emailed_another_way_puts_nothing_in_gmail(): void
    {
        Queue::fake();
        Mail::fake();
        Setting::put('mail_method', 'server');
        $this->gmail->down = true;
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $waiting = InvoiceEmails::latest($invoice);
        $this->assertSame('pending', $waiting->status);

        // Meanwhile: Email it from Expend. The retry then finds it sent, and leaves Gmail alone.
        app(InvoiceMailer::class)->send($invoice->fresh());
        $this->gmail->down = false;
        (new PrepareInvoiceEmail($invoice->id))->handle($this->emails());
        $this->assertSame([], $this->gmail->drafts);
        $this->assertSame(['replaced', 'Sent another way'], [$waiting->fresh()->status, $waiting->fresh()->error]);
        $this->assertNull($this->audit('invoice.gmail_failed'));
    }

    public function test_with_no_queue_to_retry_from_a_failure_is_said_at_once(): void
    {
        $this->gmail->down = true;
        $result = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05');
        $this->assertSame('failed', $result['email']->status);
        $this->assertSame('INV-0001 issued. It isn’t in your Gmail drafts: Gmail didn’t answer. Try again, or email it from Expend.', $result['message']);
    }

    public function test_without_gmail_it_is_refused_before_issuing(): void
    {
        Setting::put('gmail_scopes', ['https://www.googleapis.com/auth/gmail.readonly']);
        $draft = $this->draftInvoice();
        $this->assertSame('Connect Gmail (with permission to write drafts) to put invoices in the booking conversation: issue it only, or email it from Expend.',
            $this->refusal(fn () => $this->emails()->issueToGmail($draft, '2026-06-05')));
        $this->assertSame('draft', $draft->fresh()->status, 'Not issued');
    }

    public function test_a_send_from_gmail_is_seen_and_recorded_as_the_systems(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $row = InvoiceEmails::open($invoice);
        $this->travelTo('2026-06-05 11:30:00');
        $sent = $this->gmail->sendByHand($row->gmail_draft_id);

        (new CheckInvoiceEmails)->handle($this->emails(), app(ChaseLadder::class));
        $row->refresh();
        $invoice->refresh();
        $rfc = trim($sent['headers']['Message-ID'], '<>');
        $this->assertSame(['sent', $sent['id'], $rfc], [$row->status, $row->gmail_message_id, $row->rfc_message_id]);
        $this->assertSame(['2026-06-05 11:30:00', 'accounts@acme.example.test', $rfc], [$invoice->sent_at->format('Y-m-d H:i:s'), $invoice->sent_to, $invoice->message_id]);
        $event = $this->audit('invoice.sent');
        $this->assertSame(['system', 'Sent from your Gmail'], [$event->actor, $event->reason]);
        $this->assertSame(['to' => 'accounts@acme.example.test', 'number' => 'INV-0001', 'message_id' => $rfc, 'route' => 'gmail'], $event->after);
        $this->assertSame('sent', JobStage::of([$invoice->job])[$invoice->work_job_id]['stage']);
    }

    public function test_the_message_id_from_gmail_is_kept_only_when_the_invoice_has_none(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->issuedInvoice();
        app(InvoiceMailer::class)->send($invoice);
        $id = $invoice->fresh()->message_id;
        Mail::queued(InvoiceMail::class)->first()->failed(new \RuntimeException('Mailbox refused the login'));
        $this->assertNull($invoice->fresh()->sent_at);

        $row = $this->emails()->prepare($invoice->fresh(), 'invoice', []);
        $this->gmail->sendByHand($row->gmail_draft_id);
        $this->assertSame('sent', $this->emails()->check($row));
        $this->assertSame($id, $invoice->fresh()->message_id, 'The invoice’s Message-ID never changes once given');
        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    public function test_a_draft_deleted_unsent_is_seen_and_can_be_put_back(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $row = InvoiceEmails::open($invoice);
        $this->gmail->deleteByHand($row->gmail_draft_id);

        $this->assertSame(['checked' => 1, 'sent' => 0, 'deleted' => 1, 'removed' => 0, 'later' => 0, 'blind' => false], $this->emails()->checkAll());
        $this->assertSame('deleted', $row->fresh()->status);
        $this->assertSame(['system', 'The Gmail draft was deleted without being sent'], [$this->audit('invoice.gmail_draft_deleted')->actor, $this->audit('invoice.gmail_draft_deleted')->reason]);
        $this->assertSame('Gmail draft deleted, not sent', JobStage::of([$invoice->job])[$invoice->work_job_id]['chip']);
        $this->assertNull($invoice->fresh()->sent_at);

        // A sent message without the PDF isn't taken as the invoice going: it's still "deleted", and you're asked.
        // [Put it back in Gmail]: a new draft.
        $again = $this->emails()->prepare($invoice, 'invoice', []);
        $this->assertSame('drafted', $again->status);
        $this->assertNotSame($row->id, $again->id);
        $this->gmail->sendByHand($again->gmail_draft_id, keepPdf: false);
        $this->assertSame('deleted', $this->emails()->check($again));
    }

    public function test_when_gmail_cant_be_read_it_says_so_until_a_check_works(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->status = 401;
        $out = $this->emails()->checkAll();
        $this->assertTrue($out['blind']);
        $this->assertSame('drafted', InvoiceEmails::open($invoice)->status, 'Nothing is decided while Gmail can’t be read');
        $this->assertSame('Gmail said: HTTP 401', InvoiceEmails::cantSee()['why']);
        $since = InvoiceEmails::cantSee()['since'];

        // Disconnected: still blind, since the same time.
        $this->gmail->status = null;
        Setting::put('gmail_refresh_token', null);
        $this->assertTrue($this->emails()->checkAll()['blind']);
        $this->assertSame($since, InvoiceEmails::cantSee()['since']);

        // Connected again: the next check works and clears it.
        Setting::put('gmail_refresh_token', 'rt');
        $this->assertFalse($this->emails()->checkAll()['blind']);
        $this->assertNull(InvoiceEmails::cantSee());

        // Gmail not answering is not "can't see": tried again later.
        $this->gmail->down = true;
        $this->assertSame(['checked' => 0, 'sent' => 0, 'deleted' => 0, 'removed' => 0, 'later' => 1, 'blind' => false], $this->emails()->checkAll());
        $this->assertNull(InvoiceEmails::cantSee());
    }

    public function test_send_now_sends_only_the_version_you_saw(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $preview = $this->emails()->preview($invoice);
        $this->assertSame(['Alex Smith Sound <alex@example.test>', 'accounts@acme.example.test', 'Re: PO 4471 for Arena load-in', ['INV-0001.pdf'], false],
            [$preview['from'], $preview['to'], $preview['subject'], $preview['files'], $preview['edited']]);
        $this->assertStringStartsWith("Hello,\nPlease find attached invoice INV-0001 for Arena load-in, 3–4 Jun 2026 (PO 4471).", $preview['text']);

        // Edited in Gmail since: refused, nothing sent.
        $row = InvoiceEmails::open($invoice);
        $this->gmail->edit($row->gmail_draft_id, ['Subject' => 'Re: PO 4471 for Arena load-in (with the hotel)']);
        try {
            $this->emails()->send($invoice, $preview['version']);
            $this->fail('An edited draft isn’t sent from an old look');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(['You’ve changed it in Gmail since: send it there, or look again.', InvoiceService::CHANGED], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertSame([], $this->gmail->sent());

        // Looked again: sent, as you.
        $again = $this->emails()->preview($invoice);
        $this->assertTrue($again['edited']);
        $this->assertSame('Invoice INV-0001 is on its way to accounts@acme.example.test.', $this->emails()->send($invoice, $again['version']));
        $this->assertCount(1, $this->gmail->sent());
        $this->assertSame('Re: PO 4471 for Arena load-in (with the hotel)', $this->gmail->sent()[0]['headers']['Subject']);
        $this->assertSame(['sent', 'you'], [InvoiceEmails::latest($invoice)->status, $this->audit('invoice.sent')->actor]);
        $this->assertNull($this->audit('invoice.sent')->reason);
        $this->assertNotNull($invoice->fresh()->sent_at);

        // Pressed again: it's gone, so nothing more is sent.
        $this->assertSame('Invoice INV-0001 isn’t in your Gmail drafts.', $this->refusal(fn () => $this->emails()->send($invoice, $again['version'])));
        $this->assertCount(1, $this->gmail->sent());
    }

    public function test_issue_and_send_issues_drafts_and_sends_in_one_press(): void
    {
        $draft = $this->draftInvoice();
        $this->assertSame('Issue and send needs the address it goes to: pick one, or put it in Gmail drafts instead.',
            $this->refusal(fn () => $this->emails()->issueAndSend($draft, ' ', '2026-06-05')));
        $this->assertSame('The total changed since you looked: check it and issue again.',
            $this->refusal(fn () => $this->emails()->issueAndSend($draft, 'accounts@acme.example.test', '2026-06-05', ['total' => 1])));
        $this->assertSame('draft', $draft->fresh()->status);

        $result = $this->emails()->issueAndSend($draft, 'accounts@acme.example.test', '2026-06-05', ['total' => 65000]);
        $this->assertTrue($result['sent']);
        $this->assertSame('INV-0001 issued. Invoice INV-0001 is on its way to accounts@acme.example.test.', $result['message']);
        $this->assertSame([], $this->gmail->drafts);
        $this->assertCount(1, $this->gmail->sent());
        $this->assertSame($this->po['thread'], $this->gmail->sent()[0]['threadId']);
        $this->assertSame(['invoice.issued', 'invoice.gmail_drafted', 'invoice.sent'],
            AuditEvent::query()->where('subject_type', 'Invoice')->whereIn('action', ['invoice.issued', 'invoice.gmail_drafted', 'invoice.sent'])->orderBy('id')->pluck('action')->all());
        $this->assertSame(['you'], AuditEvent::query()->whereIn('action', ['invoice.gmail_drafted', 'invoice.sent'])->distinct()->pluck('actor')->all());
        $this->assertSame('accounts@acme.example.test', $result['invoice']->sent_to);
    }

    public function test_issue_and_send_never_sends_twice_when_gmail_is_slow(): void
    {
        Queue::fake();
        $this->gmail->timeoutAfterCreate = true;
        $result = $this->emails()->issueAndSend($this->draftInvoice(), 'accounts@acme.example.test', '2026-06-05');
        $this->assertFalse($result['sent']);
        $this->assertSame('INV-0001 issued, but it isn’t sent: putting it in Gmail… Gmail didn’t answer, so Expend will try again in a minute.', $result['message']);
        $this->assertSame([], $this->gmail->sent());
        (new PrepareInvoiceEmail($result['invoice']->id))->handle($this->emails());
        $this->assertSame([], $this->gmail->sent(), 'The retry only puts it in your drafts: you press Send');
        $this->assertCount(1, $this->gmail->drafts);
    }

    public function test_the_mailer_refuses_while_the_gmail_draft_is_open(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $mailer = app(InvoiceMailer::class);
        $this->assertSame('Invoice INV-0001 is in your Gmail drafts, ready to send: send it from there, or delete it there first.', $this->refusal(fn () => $mailer->send($invoice)));
        $this->assertSame('Invoice INV-0001 is in your Gmail drafts, ready to send: send it from there, or delete it there first.', $mailer->send($invoice, once: true));
        Mail::assertNothingQueued();

        // Deleted in Gmail: [Email it from Expend] works, and records its own row.
        $this->gmail->deleteByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $this->emails()->checkAll();
        $this->assertSame('Invoice INV-0001 is on its way to accounts@acme.example.test.', $mailer->send($invoice->fresh()));
        $row = InvoiceEmails::latest($invoice);
        $this->assertSame(['mailer', 'sent', 'accounts@acme.example.test', $invoice->fresh()->message_id], [$row->route, $row->status, $row->to_address, $row->rfc_message_id]);
    }

    public function test_i_sent_it_myself_marks_it_sent_and_takes_out_expends_unedited_draft(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $row = InvoiceEmails::open($invoice);
        $this->assertSame('Invoice INV-0001 is marked as sent. The Gmail draft of INV-0001 was taken out of your drafts: you sent it another way.',
            $this->emails()->markSentSelf($invoice));
        $this->assertSame([], $this->gmail->drafts);
        $this->assertSame('replaced', $row->fresh()->status);
        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertNull($invoice->fresh()->sent_to);
        $this->assertSame(['by_hand', 'sent'], [InvoiceEmails::latest($invoice)->route, InvoiceEmails::latest($invoice)->status]);
        $this->assertSame(['you', 'You sent it yourself'], [$this->audit('invoice.marked_sent')->actor, $this->audit('invoice.marked_sent')->reason]);
        $this->assertSame('Invoice INV-0001 was already marked as sent on 5 Jun 2026.', $this->emails()->markSentSelf($invoice));

        // Without Gmail at all: still marked by hand (the fallback), with the address when you give one.
        Setting::put('gmail_refresh_token', null);
        $other = $this->issuedInvoice($this->job('Gala dinner', po: false));
        $this->assertSame('Invoice INV-0002 is marked as sent.', $this->emails()->markSentSelf($other, 'events@acme.example.test'));
        $this->assertSame('events@acme.example.test', $other->fresh()->sent_to);
    }

    public function test_a_draft_already_sent_from_gmail_is_seen_before_marking_it_by_hand(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $this->assertSame('Invoice INV-0001 was sent from your Gmail: it’s marked as sent.', $this->emails()->markSentSelf($invoice));
        $this->assertSame('sent', InvoiceEmails::latest($invoice)->status);
        $this->assertNull($this->audit('invoice.marked_sent'));
    }

    public function test_one_tap_send_only_for_a_draft_as_expend_made_it_to_an_address_used_before(): void
    {
        // A first invoice to Acme went to accounts@ (sent by hand).
        $first = $this->issuedInvoice($this->job('Gala dinner', po: false));
        $first->forceFill(['sent_at' => now(), 'sent_to' => 'accounts@acme.example.test'])->save();

        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->assertNull($this->emails()->oneTapProblem($invoice));

        // A new address: not one-tap.
        $other = $this->emails()->issueToGmail($this->draftInvoice($this->job('Bristol fair', po: false)), '2026-06-05', null, 'new@acme.example.test')['invoice'];
        $this->assertSame('No earlier invoice to this client went to that address: send it from Gmail or Expend, where you can see it.', $this->emails()->oneTapProblem($other));
        $this->assertStringNotContainsString('new@', (string) $this->emails()->oneTapProblem($other), 'The reason never shows the address');

        // Edited in Gmail: not one-tap.
        $row = InvoiceEmails::open($invoice);
        $draftId = $row->gmail_draft_id;
        $this->gmail->edit($draftId, ['Cc' => 'boss@acme.example.test']);
        $this->assertSame('It was changed in Gmail: send it there.', $this->emails()->oneTapProblem($invoice));
        $this->assertSame('Not sent: it was changed in Gmail: send it there.', $this->emails()->sendOneTap($invoice));
        $this->assertSame([], $this->gmail->sent());
    }

    public function test_one_tap_send_sends_the_draft_as_made(): void
    {
        $first = $this->issuedInvoice($this->job('Gala dinner', po: false));
        $first->forceFill(['sent_at' => now(), 'sent_to' => 'Accounts@Acme.example.test'])->save();
        // A merged duplicate's invoice counts as the client's.
        $dupe = Client::query()->create(['name' => 'Acme Events Ltd', 'merged_into_id' => $this->client()->id]);
        $invoice = $this->emails()->issueToGmail($this->draftInvoice($this->job(extra: ['client_id' => $dupe->id])), '2026-06-05')['invoice'];
        $this->assertNull($this->emails()->oneTapProblem($invoice, live: false));
        $this->assertSame('Invoice INV-0002 is on its way to accounts@acme.example.test.', $this->emails()->sendOneTap($invoice));
        $this->assertCount(1, $this->gmail->sent());
    }

    public function test_a_voided_invoices_draft_is_taken_out_and_never_sent(): void
    {
        $invoice = $this->emails()->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $row = InvoiceEmails::open($invoice);
        app(InvoiceService::class)->void($invoice, 'Wrong amount');
        $this->assertSame('Only an issued invoice can be sent.', $this->refusal(fn () => $this->emails()->send($invoice, (string) $row->draft_message_id)));
        $this->assertSame(1, $this->emails()->checkAll()['removed']);
        $this->assertSame([], $this->gmail->drafts);
        $this->assertSame(['removed', 'It was voided'], [$row->fresh()->status, $row->fresh()->error]);
        $this->assertSame('system', $this->audit('invoice.gmail_draft_removed')->actor);
    }

    public function test_a_pdf_that_isnt_the_one_issued_is_never_attached(): void
    {
        $invoice = $this->issuedInvoice();
        Storage::disk('local')->put($invoice->pdf_path, 'not the invoice');
        $row = $this->emails()->prepareOrRetry($invoice, 'invoice', []);
        $this->assertSame(['failed', 'The PDF of invoice INV-0001 isn’t the one issued, so it wasn’t put in Gmail.'], [$row->status, $row->error]);
        $this->assertSame([], $this->gmail->drafts);
    }

    public function test_an_invoice_already_sent_isnt_put_in_gmail_again(): void
    {
        $invoice = $this->issuedInvoice();
        $invoice->forceFill(['sent_at' => now(), 'sent_to' => 'accounts@acme.example.test'])->save();
        $this->assertSame('Invoice INV-0001 was already sent on 5 Jun 2026, so it wasn’t put in Gmail again.', $this->refusal(fn () => $this->emails()->prepare($invoice)));
        $this->assertSame(0, InvoiceEmail::query()->count());
        $this->assertSame(['accounts@acme.example.test'], $this->emails()->addresses($invoice));
    }

    public function test_inside_a_callers_transaction_gmail_waits_for_the_commit(): void
    {
        $draft = $this->draftInvoice();
        $result = DB::transaction(function () use ($draft) {
            $result = $this->emails()->issueToGmail($draft, '2026-06-05');
            $this->assertSame([], $this->gmail->drafts, 'Gmail isn’t called while a transaction holds locks');

            return $result;
        });
        $this->assertSame('INV-0001 issued. Putting it in Gmail…', $result['message']);
        $this->assertCount(1, $this->gmail->drafts, 'Put in Gmail once it committed');
        $this->assertSame('drafted', InvoiceEmails::latest($result['invoice'])->status);
    }

    public function test_a_line_left_off_isnt_in_the_costs(): void
    {
        $invoice = $this->issuedInvoice();
        DB::table('invoice_lines')->insert(['invoice_id' => $invoice->id, 'description' => 'Taxi: Uber, 3 Jun 2026 (at cost)', 'quantity' => 1, 'unit_price_minor' => 1820,
            'amount_minor' => 1820, 'sort' => 5, 'source' => 'cost', 'included' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2, InvoiceLine::query()->where('invoice_id', $invoice->id)->count());
        $this->assertStringNotContainsString('travel and expenses', InvoiceEmails::text($invoice->fresh()));
    }
}
