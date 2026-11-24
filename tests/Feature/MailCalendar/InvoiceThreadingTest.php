<?php

namespace Tests\Feature\MailCalendar;

use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Mail\InvoiceEmail;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** Mail and calendar batch 1: each invoice email has a fixed Message-ID, and reminders point back to it. */
class InvoiceThreadingTest extends TestCase
{
    use RefreshDatabase;

    /** The real mail manager, kept before Mail::fake(), for building messages through its array mailer. */
    private $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = app('mail.manager');
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        config(['app.url' => 'https://expend.acme-sound.example']);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    private function issuedInvoice(string $issued): Invoice
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'po_number' => 'PO-4471']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme-events.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        return app(InvoiceService::class)->issue($invoice->fresh(), $issued)->fresh();
    }

    /** The message as it leaves Expend, whichever way it goes: built through the array mailer, so nothing is sent. */
    private function built(InvoiceEmail $mail): Email
    {
        $array = $this->mail->mailer('array');
        $this->assertSame('array', (string) $array->getSymfonyTransport(), 'Built, never sent');
        $array->to('accounts@acme-events.example')->send($mail);

        return $array->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    public function test_the_invoice_has_one_message_id_kept_across_resends_and_reminders_point_to_it(): void
    {
        Setting::put('mail_method', 'smtp');
        Setting::put('smtp_host', 'smtp.example');
        Setting::put('smtp_username', 'invoices@acme-sound.example');
        Setting::put('smtp_password', 'not-a-real-password');
        Mail::fake();
        $invoice = $this->issuedInvoice('2026-10-15');
        $this->assertNull($invoice->message_id);

        app(InvoiceMailer::class)->send($invoice);
        $id = $invoice->fresh()->message_id;
        $this->assertMatchesRegularExpression('/^invoice-[a-z0-9-]+\.[0-9a-f]{16}@acme-sound\.example$/', $id, 'On the From address’s domain');
        $this->assertSame($id, AuditEvent::query()->where('action', 'invoice.sent')->latest('id')->value('after')['message_id']);

        // Sent again (to another address): the same Message-ID.
        app(InvoiceMailer::class)->send($invoice->fresh(), 'finance@acme-events.example');
        $this->assertSame($id, $invoice->fresh()->message_id);

        // A reminder once it's overdue.
        $this->travelTo(now()->setDate(2026, 11, 24));
        app(InvoiceMailer::class)->send($invoice->fresh(), reminder: true);
        $this->assertSame($id, $invoice->fresh()->message_id);

        $queued = Mail::queued(InvoiceEmail::class)->values();
        $this->assertCount(3, $queued);
        $this->assertSame([$id, $id, $id], $queued->map(fn (InvoiceEmail $m) => $m->threadId)->all());

        [$first, $again, $reminder] = $queued->map(fn (InvoiceEmail $m) => $this->built(unserialize(serialize($m))))->all();
        $this->assertSame([$id], $first->getHeaders()->get('Message-ID')->getIds());
        $this->assertSame([$id], $again->getHeaders()->get('Message-ID')->getIds(), 'Stable across resends');
        $this->assertNull($first->getHeaders()->get('In-Reply-To'));

        $this->assertNotSame([$id], $reminder->getHeaders()->get('Message-ID')?->getIds() ?? [], 'A reminder is its own message');
        $this->assertSame('<'.$id.'>', $reminder->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<'.$id.'>', $reminder->getHeaders()->get('References')->getBodyAsString());
        $this->assertStringStartsWith('Reminder: invoice ', $reminder->getSubject(), 'Each email still stands alone');
    }

    public function test_by_the_web_servers_mail_the_id_is_on_the_sites_domain_and_reminders_thread_too(): void
    {
        Setting::put('mail_method', 'server');
        Mail::fake();
        $invoice = $this->issuedInvoice('2026-09-01');

        // Chased before it was ever emailed from Expend (sent another way): the reminder still points to the invoice's id.
        app(InvoiceMailer::class)->send($invoice, reminder: true);
        $id = $invoice->fresh()->message_id;
        $this->assertStringEndsWith('@acme-sound.example', $id, 'noreply@ on the site’s domain');
        app(InvoiceMailer::class)->send($invoice->fresh());
        $this->assertSame($id, $invoice->fresh()->message_id);

        $queued = Mail::queued(InvoiceEmail::class)->values()->all();
        [$reminder, $sent] = array_map(fn (InvoiceEmail $m) => $this->built(unserialize(serialize($m))), $queued);
        $this->assertSame('<'.$id.'>', $reminder->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<'.$id.'>', $reminder->getHeaders()->get('References')->getBodyAsString());
        $this->assertSame([$id], $sent->getHeaders()->get('Message-ID')->getIds());
    }

    public function test_an_email_queued_without_a_thread_still_sends_plainly(): void
    {
        // Queued before this release (no Message-ID): it loads and goes as before, with no threading headers.
        $invoice = $this->issuedInvoice('2026-10-15');
        $message = $this->built(unserialize(serialize(new InvoiceEmail($invoice, true))));
        $this->assertNull($message->getHeaders()->get('In-Reply-To'));
        $this->assertNull($message->getHeaders()->get('References'));
    }

    public function test_the_message_id_never_changes_once_given(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2026-10-15');
        app(InvoiceMailer::class)->send($invoice, 'accounts@acme-events.example');

        $this->expectException(LogicException::class);
        $invoice->fresh()->forceFill(['message_id' => 'other@acme-sound.example'])->save();
    }

    public function test_a_failed_email_keeps_the_message_id_for_the_next_try(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2026-10-15');
        app(InvoiceMailer::class)->send($invoice);
        $id = $invoice->fresh()->message_id;
        $mail = Mail::queued(InvoiceEmail::class)->first();

        $mail->failed(new \RuntimeException('Mailbox refused the login'));
        $this->assertNull($invoice->fresh()->sent_at, 'Not marked as sent');
        $this->assertSame($id, $invoice->fresh()->message_id);
    }
}
