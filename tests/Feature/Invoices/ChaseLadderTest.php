<?php

namespace Tests\Feature\Invoices;

use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Ledger\AllocationWriter;
use App\Jobs\CheckInvoiceEmails;
use App\Mail\InvoiceEmail as InvoiceMail;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S7, with the owner's answer: reminders are Gmail drafts made only when you ask ("Make a reminder"), worded
 * by how overdue the invoice is; Expend never writes one on a schedule, and takes its own unedited reminder out again
 * once the invoice is paid, closed or voided, or a payment that may be for it is waiting.
 */
class ChaseLadderTest extends TestCase
{
    use InvoiceEmailFixtures;
    use RefreshDatabase;

    private const STAGE_1 = "Hello,\n\nA reminder that invoice INV-0001 for Arena load-in, 3–4 Jun 2026 (PO 4471), for £650.00, was due on 5 July 2026 and I haven’t received payment yet."
        ." The invoice is attached again.\n\nIf you’ve already paid, thank you, and please ignore this.\n\nMy bank details are on the invoice.\n\nMany thanks,\nAlex Smith Sound\n";

    private int $refunds = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvoiceEmails();
        $this->travelTo('2026-06-05 10:00:00');
    }

    private function ladder(): ChaseLadder
    {
        return app(ChaseLadder::class);
    }

    /** Issued on 5 June (due 5 July), put in Gmail and sent from there the same day. */
    private function sentInvoice(): Invoice
    {
        $emails = app(InvoiceEmails::class);
        $invoice = $emails->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $this->assertSame('sent', $emails->check(InvoiceEmails::open($invoice)));

        return $invoice->fresh();
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

    private function reminder(Invoice $invoice): ?array
    {
        $row = InvoiceEmails::open($invoice, 'reminder');

        return $row ? $this->gmail->draft($row->gmail_draft_id) : null;
    }

    public function test_how_overdue_counts_from_the_later_of_the_due_date_and_sent_plus_terms(): void
    {
        $invoice = $this->sentInvoice();
        $on = fn (string $day) => ChaseLadder::overdue($invoice, CarbonImmutable::parse($day));
        $this->assertNull($on('2026-07-05'), 'Due today: not overdue');
        $this->assertSame(['days' => 1, 'stage' => 1, 'from' => '2026-07-05'], $on('2026-07-06'));
        $this->assertSame(['days' => 13, 'stage' => 1, 'from' => '2026-07-05'], $on('2026-07-18'));
        $this->assertSame(['days' => 14, 'stage' => 2, 'from' => '2026-07-05'], $on('2026-07-19'));
        $this->assertSame(['days' => 30, 'stage' => 3, 'from' => '2026-07-05'], $on('2026-08-04'));

        // Sent late (20 June, 30 days' terms): chased from 20 July, not 5 July.
        $invoice->forceFill(['sent_at' => '2026-06-20 09:00:00'])->save();
        $this->assertNull($on('2026-07-10'));
        $this->assertSame(['days' => 3, 'stage' => 1, 'from' => '2026-07-20'], $on('2026-07-23'));
    }

    public function test_make_a_reminder_replies_to_the_sent_invoice_in_its_conversation(): void
    {
        $invoice = $this->sentInvoice();
        $sent = $this->gmail->sent()[0];
        $this->travelTo('2026-07-08 09:00:00');

        $made = $this->ladder()->make($invoice);
        $this->assertSame('A reminder for INV-0001 (3 days overdue) is in your Gmail drafts, ready to send.', $made['message']);
        $row = $made['email'];
        $this->assertSame(['reminder', 1, 'drafted', 'accounts@acme.example.test', $this->po['thread']], [$row->kind, $row->stage, $row->status, $row->to_address, $row->gmail_thread_id]);

        $draft = $this->reminder($invoice);
        $this->assertSame($this->po['thread'], $draft['threadId']);
        $this->assertSame('Re: PO 4471 for Arena load-in', $draft['headers']['Subject']);
        $this->assertSame($sent['headers']['Message-ID'], $draft['headers']['In-Reply-To'], 'It replies to the invoice as sent');
        $this->assertSame('<'.$this->po['message_id'].'> '.$sent['headers']['Message-ID'], $draft['headers']['References']);
        $this->assertSame('accounts@acme.example.test', $draft['headers']['To']);
        $this->assertSame($invoice->id.'/reminder1/'.substr($invoice->pdf_sha256, 0, 8), $draft['headers']['X-Expend-Ref']);
        $this->assertSame($invoice->pdf_sha256, hash('sha256', $draft['files']['INV-0001.pdf']));
        $this->assertSame(trim(self::STAGE_1), trim($draft['text']));
        $event = AuditEvent::query()->where('action', 'invoice.reminder_drafted')->sole();
        $this->assertSame(['you', 1], [$event->actor, $event->after['stage']]);
        $this->assertNull(Invoice::query()->find($invoice->id)->reminded_at, 'Nothing is sent until you press Send');

        // Asked again at the same stage: the same draft.
        $this->assertSame('The reminder for INV-0001 is already in your Gmail drafts, ready to send.', $this->ladder()->make($invoice)['message']);
        $this->assertSame(1, InvoiceEmail::query()->where('kind', 'reminder')->count());
    }

    public function test_the_wording_goes_by_how_overdue_it_is_and_what_has_come_in(): void
    {
        $invoice = $this->sentInvoice();
        $this->assertSame(self::STAGE_1, ChaseLadder::text($invoice, 1));
        $this->assertSame("Hello,\n\nInvoice INV-0001 for Arena load-in, 3–4 Jun 2026 (PO 4471), for £650.00, was due on 5 July 2026 and is still unpaid. The invoice is attached again.\n\n"
            ."Could you let me know when it will be paid?\n\nIf you’ve already paid, thank you, and please ignore this.\n\nMy bank details are on the invoice.\n\nMany thanks,\nAlex Smith Sound\n",
            ChaseLadder::text($invoice, 2));
        $this->assertSame("Hello,\n\nInvoice INV-0001 for Arena load-in, 3–4 Jun 2026 (PO 4471), for £650.00, was due on 5 July 2026 and is now over a month overdue. The invoice is attached again.\n\n"
            ."Please could you pay it this week, or let me know if there’s a problem with it?\n\nIf you’ve already paid, thank you, and please ignore this.\n\n"
            ."My bank details are on the invoice.\n\nMany thanks,\nAlex Smith Sound\n", ChaseLadder::text($invoice, 3));

        $this->income(45000, $invoice->job, on: '2026-07-03');
        $this->assertSame("Hello,\n\nThank you for the £450 received on 3 July. £200 of invoice INV-0001 for Arena load-in, 3–4 Jun 2026 (PO 4471) is still to come: it was due on 5 July 2026."
            ." The invoice is attached again.\n\nIf you’ve already paid, thank you, and please ignore this.\n\nMy bank details are on the invoice.\n\nMany thanks,\nAlex Smith Sound\n",
            ChaseLadder::text($invoice, 1, CarbonImmutable::parse('2026-07-10')));
        $this->assertStringNotContainsString('interest', ChaseLadder::text($invoice, 3));
    }

    public function test_one_reminder_open_at_a_time_an_unedited_one_is_replaced_an_edited_one_kept(): void
    {
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-08 09:00:00');
        $first = $this->ladder()->make($invoice)['email'];

        // 14 days: Expend's unedited stage 1 draft is replaced by stage 2.
        $this->travelTo('2026-07-19 09:00:00');
        $second = $this->ladder()->make($invoice)['email'];
        $this->assertSame([2, 'drafted'], [$second->stage, $second->status]);
        $this->assertSame(['replaced', 'A later reminder replaced it'], [$first->fresh()->status, $first->fresh()->error]);
        $this->assertNull($this->gmail->draft($first->gmail_draft_id));
        $this->assertCount(1, $this->gmail->drafts);
        $this->assertStringContainsString('Could you let me know when it will be paid?', $this->reminder($invoice)['text']);
        $removed = AuditEvent::query()->where('action', 'invoice.gmail_draft_removed')->sole();
        $this->assertSame(['you', 'A later reminder replaced it'], [$removed->actor, $removed->reason]);

        // You edit it in Gmail: at 30 days it's left, and the next one waits.
        $this->gmail->edit($second->gmail_draft_id, text: 'My own words');
        $this->travelTo('2026-08-04 09:00:00');
        $this->assertSame('Your edited reminder for INV-0001 is still in your Gmail drafts: send or delete it there first.', $this->refusal(fn () => $this->ladder()->make($invoice)));
        $this->assertSame('My own words', $this->reminder($invoice)['text']);
        $this->assertSame(1, InvoiceEmail::query()->open()->where('kind', 'reminder')->count());
    }

    public function test_when_gmail_doesnt_answer_no_reminder_is_made_and_you_are_told(): void
    {
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-08 09:00:00');
        $this->gmail->down = true;
        $made = $this->ladder()->make($invoice);
        $this->assertSame('The reminder for INV-0001 isn’t in your Gmail drafts: Gmail didn’t answer.', $made['message']);
        $this->assertSame(['reminder', 'failed'], [$made['email']->kind, $made['email']->status]);

        $this->gmail->down = false;
        $this->ladder()->make($invoice);
        $this->travelTo('2026-07-20 09:00:00');
        $this->gmail->down = true;
        $this->assertSame('Gmail didn’t answer, so no reminder was made: try again in a minute.', $this->refusal(fn () => $this->ladder()->make($invoice)));
        $this->assertSame(1, InvoiceEmail::query()->open()->where('kind', 'reminder')->count(), 'The stage 1 draft is left as it was');
    }

    public function test_it_isnt_made_before_sending_once_paid_or_too_early(): void
    {
        $issued = $this->issuedInvoice($this->job('Gala dinner', po: false));
        $this->travelTo('2026-07-20 09:00:00');
        $this->assertSame('Invoice INV-0001 hasn’t been sent yet: send it first.', $this->refusal(fn () => $this->ladder()->make($issued)));

        $this->travelTo('2026-06-05 10:00:00');
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-05 09:00:00');
        $this->assertSame('Invoice INV-0002 isn’t overdue yet (it’s due on 5 Jul 2026), so it’s too early for a reminder.', $this->refusal(fn () => $this->ladder()->make($invoice)));

        $this->travelTo('2026-07-10 09:00:00');
        $this->income(65000, $invoice->job);
        $this->assertSame('Invoice INV-0002 is paid in full, so no reminder was made.', $this->refusal(fn () => $this->ladder()->make($invoice)));
        $this->assertSame([], $this->gmail->drafts);

        Setting::put('gmail_scopes', ['https://www.googleapis.com/auth/gmail.readonly']);
        $this->assertSame('Connect Gmail (with permission to write drafts) to make a reminder there, or send one from Expend.', $this->refusal(fn () => $this->ladder()->make($invoice)));
    }

    public function test_a_one_tap_reminder_waits_for_money_that_may_be_for_it_the_invoice_page_can_still_ask(): void
    {
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-10 09:00:00');
        $this->income(10000, $invoice->job, $invoice, confirmed: false);
        $this->assertSame(['email' => null, 'message' => 'No reminder was made: £100.00 that may be for invoice INV-0001 is waiting in the Inbox. Decide it first. To make one anyway, use the invoice’s page.'],
            $this->ladder()->make($invoice, once: true));
        $this->assertSame([], $this->gmail->drafts);
        $this->assertSame('drafted', $this->ladder()->make($invoice)['email']->status, 'From the invoice’s page you can still ask');
    }

    public function test_money_paid_back_or_paid_against_a_voided_invoice_holds_one_tap_reminders(): void
    {
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-10 09:00:00');
        $turnover = (int) Category::query()->where('code', 'turnover')->value('id');
        $this->income(20000, $invoice->job, $invoice, on: '2026-07-01');
        app(AllocationWriter::class)->decide($this->money(-20000), [['kind' => 'business_income', 'category_id' => $turnover, 'work_job_id' => $invoice->work_job_id,
            'invoice_id' => $invoice->id, 'amount_pence' => -20000]]);
        $this->assertSame('No reminder was made: you paid back £200.00 against invoice INV-0001. Void it if the work was cancelled, or close it if the rest isn’t owed. To make one anyway, use the invoice’s page.',
            $this->ladder()->make($invoice, once: true)['message']);

        // The mailer holds its one-tap reminder the same way (income design v3).
        Mail::fake();
        Setting::put('mail_method', 'server');
        $this->assertSame('No reminder was sent: you paid back £200.00 against invoice INV-0001. Void it if the work was cancelled, or close it if the rest isn’t owed. To send one anyway, use the invoice’s page.',
            app(InvoiceMailer::class)->send($invoice->fresh(), reminder: true, once: true));
        Mail::assertNothingQueued();
    }

    public function test_a_payment_on_a_voided_invoice_of_the_client_holds_reminders_until_you_say_what_replaced_it(): void
    {
        $invoice = $this->sentInvoice();
        $loose = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0009', 'client_id' => $this->client()->id, 'currency' => 'GBP', 'total_minor' => 15000,
            'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-01', 'due_date' => '2026-05-31', 'issued_at' => '2026-05-01 09:00:00']);
        app(AllocationWriter::class)->decide($this->money(15000), [['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'),
            'invoice_id' => $loose->id, 'client_id' => $this->client()->id, 'amount_pence' => 15000]]);
        $loose->forceFill(['status' => 'void', 'void_reason' => 'Wrong client'])->save();

        $this->travelTo('2026-07-10 09:00:00');
        $this->assertSame('No reminder was made: £150.00 was paid against INV-0009, which you voided. Say which invoice replaced it first. To make one anyway, use the invoice’s page.',
            $this->ladder()->make($invoice, once: true)['message']);
        Mail::fake();
        Setting::put('mail_method', 'server');
        $this->assertSame('No reminder was sent: £150.00 was paid against INV-0009, which you voided. Say which invoice replaced it first. To send one anyway, use the invoice’s page.',
            app(InvoiceMailer::class)->send($invoice->fresh(), reminder: true, once: true));
    }

    public function test_nothing_writes_a_reminder_on_a_schedule(): void
    {
        $invoice = $this->sentInvoice();
        $made = count($this->gmail->created);
        foreach (['2026-07-08', '2026-07-19', '2026-08-04', '2026-09-30'] as $day) {
            $this->travelTo($day.' 09:00:00');
            (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), $this->ladder());
            $this->assertSame([], $this->ladder()->tidy());
        }
        $this->assertSame($made, count($this->gmail->created));
        $this->assertSame(0, InvoiceEmail::query()->where('kind', 'reminder')->count());
        $this->assertNull(AuditEvent::query()->where('action', 'invoice.reminder_drafted')->first());
    }

    public function test_its_own_reminder_is_taken_out_when_a_payment_may_be_for_it_or_it_is_paid(): void
    {
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-10 09:00:00');
        $row = $this->ladder()->make($invoice)['email'];

        // A payment that may be for it arrives: Expend takes its own unedited reminder out, and says so.
        $this->income(65000, $invoice->job, $invoice, confirmed: false);
        $this->assertSame(['The reminder for INV-0001 was taken out of your drafts: a payment may be for it.'], $this->ladder()->tidy());
        $this->assertSame([], $this->gmail->drafts);
        $this->assertSame(['removed', 'A payment may be for it'], [$row->fresh()->status, $row->fresh()->error]);
        $event = AuditEvent::query()->where('action', 'invoice.gmail_draft_removed')->sole();
        $this->assertSame(['system', 'A payment may be for it'], [$event->actor, $event->reason]);
    }

    public function test_once_paid_its_own_reminder_goes_but_one_you_edited_stays(): void
    {
        $invoice = $this->sentInvoice();
        $other = app(InvoiceEmails::class)->issueToGmail($this->draftInvoice($this->job('Gala dinner', po: false)), '2026-06-05')['invoice'];
        $this->gmail->sendByHand(InvoiceEmails::open($other)->gmail_draft_id);
        app(InvoiceEmails::class)->checkAll();
        $this->travelTo('2026-07-10 09:00:00');
        $row = $this->ladder()->make($invoice)['email'];
        $this->gmail->edit($row->gmail_draft_id, text: 'Hi Sam, just checking on this one.');
        $this->income(65000, $invoice->job);

        (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), $this->ladder());
        $this->assertCount(1, $this->gmail->drafts, 'Your words are never deleted');
        $this->assertSame(['drafted', 'You edited it, so it was left in your drafts: it’s paid.'], [$row->fresh()->status, $row->fresh()->error]);
        $this->assertNull(AuditEvent::query()->where('action', 'invoice.gmail_draft_removed')->first());

        // Unedited: taken out once it's paid.
        $reminder = $this->ladder()->make($other->fresh())['email'];
        $this->income(65000, $other->job);
        $this->assertSame(['The reminder for INV-0002 was taken out of your drafts: it’s paid.'], $this->ladder()->tidy());
        $this->assertSame('removed', $reminder->fresh()->status);
    }

    public function test_a_reminder_sent_from_gmail_is_counted_and_the_mailer_waits_while_one_is_open(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->sentInvoice();
        $this->travelTo('2026-07-10 09:00:00');
        $row = $this->ladder()->make($invoice)['email'];
        $this->assertSame('The reminder for invoice INV-0001 is in your Gmail drafts: send it from there, or delete it there first.',
            $this->refusal(fn () => app(InvoiceMailer::class)->send($invoice->fresh(), reminder: true)));
        Mail::assertNothingQueued();

        $this->gmail->sendByHand($row->gmail_draft_id);
        app(InvoiceEmails::class)->checkAll();
        $invoice->refresh();
        $this->assertSame(['sent', '2026-07-10 09:00:00', 1], [$row->fresh()->status, $invoice->reminded_at->format('Y-m-d H:i:s'), (int) $invoice->reminder_count]);
        $event = AuditEvent::query()->where('action', 'invoice.reminder_sent')->sole();
        $this->assertSame(['system', 'Sent from your Gmail'], [$event->actor, $event->reason]);
    }

    public function test_an_invoice_emailed_from_expend_gets_a_new_email_pointing_back_to_it(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->issuedInvoice();
        app(InvoiceMailer::class)->send($invoice);
        $messageId = $invoice->fresh()->message_id;
        $this->assertCount(1, Mail::queued(InvoiceMail::class));

        $this->travelTo('2026-07-10 09:00:00');
        $row = $this->ladder()->make($invoice->fresh())['email'];
        $draft = $this->gmail->draft($row->gmail_draft_id);
        $this->assertNotSame($this->po['thread'], $row->fresh()->gmail_thread_id, 'A new email, not the PO’s conversation');
        $this->assertSame('Reminder: invoice INV-0001 · Arena load-in · PO 4471', $draft['headers']['Subject']);
        $this->assertSame('<'.$messageId.'>', $draft['headers']['In-Reply-To']);
        $this->assertSame('<'.$messageId.'>', $draft['headers']['References']);
        $this->assertSame('accounts@acme.example.test', $draft['headers']['To']);
        $this->assertArrayNotHasKey('threadId', $this->gmail->created[0]['message']);
    }

    private function money(int $pence): BankTransaction
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 'refunds.csv'],
            ['file_sha256' => str_repeat('c', 64), 'stored_path' => 'x', 'status' => 'committed']);

        return BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-07-02',
            'tax_year' => UkTaxYear::fromDate('2026-07-02')->label(), 'description' => 'ACME EVENTS', 'counterparty' => 'Acme Events', 'merchant_key' => 'ACME EVENTS',
            'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'chase-'.(++$this->refunds)]);
    }
}
