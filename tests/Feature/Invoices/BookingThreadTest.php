<?php

namespace Tests\Feature\Invoices;

use App\Domain\Gmail\GmailLabeller;
use App\Domain\Invoices\BookingThread;
use App\Models\Client;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Build slice S7: which Gmail conversation an invoice goes in. The PO's first, then the enquiry's or offer's, then other
 * conversations linked to the job from the client's own domains, newest first; none means a new email, and a client
 * whose invoices go as a new email (or who pays without one) never gets a conversation.
 */
class BookingThreadTest extends TestCase
{
    use InvoiceEmailFixtures;
    use RefreshDatabase;

    private int $docs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvoiceEmails();
    }

    private function threads(): BookingThread
    {
        return app(BookingThread::class);
    }

    /** An email linked to the job, found by a Gmail search, in a conversation. */
    private function email(WorkJob $job, string $thread, string $from, string $subject, string $at, string $kind = 'job_invite'): Document
    {
        $doc = Document::query()->create(['source' => 'email', 'original_name' => 'mail.eml', 'mime' => 'message/rfc822', 'sha256' => hash('sha256', 'doc'.(++$this->docs)),
            'stored_path' => 'x', 'kind' => $kind, 'work_job_id' => $job->id, 'email_from' => $from, 'email_subject' => $subject, 'received_at' => $at]);
        GmailMessage::query()->create(['message_id' => 'm'.$this->docs, 'thread_id' => $thread, 'search' => 'job_documents', 'document_id' => $doc->id]);

        return $doc;
    }

    /** The offer of work the job came from, found in Gmail conversation T2. */
    private function offer(WorkJob $job, array $extra = []): Opportunity
    {
        return Opportunity::query()->create($extra + ['status' => 'confirmed', 'title' => 'Arena load-in', 'work_job_id' => $job->id, 'source' => 'gmail', 'found_by' => 'rules',
            'evidence' => [], 'fingerprint' => hash('sha256', 'offer'.$job->id), 'thread_key' => 'gmail:T2', 'last_seen_at' => '2026-05-10 09:00:00']);
    }

    private function invoiceFor(WorkJob $job): Invoice
    {
        return Invoice::query()->create(['status' => 'issued', 'number' => 'INV-'.str_pad((string) (Invoice::query()->count() + 1), 4, '0', STR_PAD_LEFT), 'work_job_id' => $job->id, 'client_id' => $job->client_id, 'currency' => 'GBP',
            'total_minor' => 65000, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example.test']);
    }

    public function test_the_po_conversation_first_then_the_offer_then_others_from_the_clients_domains_newest_first(): void
    {
        $job = $this->job(po: false);
        $this->email($job, 'T1', 'Acme Buyer <buyer@acme.example.test>', 'PO 4471 for Arena load-in', '2026-05-20 09:00:00', 'po');
        $this->offer($job, ['contact_email' => 'bookings@acme-crew.example.test']);
        $this->email($job, 'T2', 'Acme Bookings <bookings@acme-crew.example.test>', 'Are you free 3-4 June?', '2026-05-10 09:00:00', 'opportunity');
        $this->email($job, 'T3', 'Crew Desk <crew@acme.example.test>', 'Call sheet', '2026-05-25 09:00:00');
        $this->email($job, 'T4', 'Hotel <stay@hotel.example.test>', 'Your booking', '2026-05-30 09:00:00');
        $this->email($job, 'T5', 'Venue Desk <venue@acme-crew.example.test>', 'Load-in times', '2026-05-28 09:00:00');
        $this->email($this->job('Other show', po: false), 'T6', 'Acme Buyer <buyer@acme.example.test>', 'Another job', '2026-05-29 09:00:00');

        $candidates = $this->threads()->candidates($this->invoiceFor($job));
        $this->assertSame([['T1', 'po'], ['T2', 'enquiry'], ['T5', 'job'], ['T3', 'job']], array_map(fn ($c) => [$c['thread_id'], $c['how']], $candidates));
        $this->assertSame(['thread_id' => 'T1', 'how' => 'po', 'subject' => 'PO 4471 for Arena load-in', 'from' => 'Acme Buyer <buyer@acme.example.test>'],
            array_diff_key($candidates[0], ['at' => true]));
        $this->assertStringStartsWith('2026-05-20T09:00:00', $candidates[0]['at']);
        Http::assertNothingSent();
    }

    public function test_none_means_a_new_email_and_a_client_set_to_new_or_none_never_gets_a_conversation(): void
    {
        $job = $this->job();
        $invoice = $this->invoiceFor($job);
        $this->assertSame([$this->po['thread']], array_column($this->threads()->candidates($invoice), 'thread_id'));

        $this->assertSame([], $this->threads()->candidates($this->invoiceFor($this->job('Gala dinner', po: false))), 'Nothing linked: a new email');
        $loose = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0099', 'currency' => 'GBP', 'total_minor' => 100, 'bill_to_name' => 'Acme Events']);
        $this->assertSame([], $this->threads()->candidates($loose), 'No job: a new email');

        foreach (['new', 'none'] as $route) {
            $this->client()->forceFill(['invoice_route' => $route])->save();
            $this->assertSame($route, $this->threads()->route($invoice));
            $this->assertSame([], $this->threads()->candidates($invoice));
        }

        // A client merged into one whose invoices go as a new email goes as a new email too.
        $this->client()->forceFill(['invoice_route' => 'new'])->save();
        $dupe = Client::query()->create(['name' => 'Acme Events Ltd', 'merged_into_id' => $this->client()->id]);
        $job->forceFill(['client_id' => $dupe->id])->save();
        $viaJob = Invoice::query()->create(['status' => 'draft', 'work_job_id' => $job->id, 'currency' => 'GBP', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);
        $this->assertSame('new', $this->threads()->route($viaJob), 'Its client through the job, merges resolved');
        $this->assertSame('thread', $this->threads()->route(new Invoice), 'The default');
    }

    public function test_a_forwarded_po_is_found_in_gmail_by_its_message_id_only_when_searching(): void
    {
        $job = $this->job(po: false);
        $original = $this->gmail->receive('Acme Buyer <buyer@acme.example.test>', 'PO 4471');
        Storage::disk('local')->put('docs/fwd.eml', "From: alex@example.test\r\nMessage-ID: <{$original['message_id']}>\r\nSubject: Fwd: PO 4471\r\n\r\nPO attached\r\n");
        $email = Document::query()->create(['source' => 'email', 'original_name' => 'fwd.eml', 'mime' => 'message/rfc822', 'sha256' => hash('sha256', 'fwd'),
            'stored_path' => 'docs/fwd.eml', 'kind' => 'email', 'work_job_id' => $job->id]);
        Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'mime' => 'application/pdf', 'sha256' => hash('sha256', 'po'),
            'stored_path' => 'x', 'kind' => 'po', 'parent_id' => $email->id, 'work_job_id' => $job->id]);
        $invoice = $this->invoiceFor($job);

        $this->assertSame([], $this->threads()->candidates($invoice), 'Without searching, only what Expend holds');
        Http::assertNothingSent();
        $this->assertSame([['thread_id' => $original['thread'], 'how' => 'po']],
            array_map(fn ($c) => array_intersect_key($c, ['thread_id' => 1, 'how' => 1]), $this->threads()->candidates($invoice, true)));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'rfc822msgid:'.$original['message_id']));

        $found = app(GmailLabeller::class)->poMessages($job);
        $this->assertSame(['ids' => [$original['id']], 'thread' => $original['thread'], 'how' => 'message_id', 'document_id' => $email->id], $found);
    }

    public function test_gmail_not_answering_still_gives_what_expend_holds(): void
    {
        $job = $this->job(po: false);
        Storage::disk('local')->put('docs/fwd.eml', "From: alex@example.test\r\nMessage-ID: <po-1@acme.example.test>\r\nSubject: Fwd: PO 4471\r\n\r\nPO\r\n");
        Document::query()->create(['source' => 'email', 'original_name' => 'fwd.eml', 'mime' => 'message/rfc822', 'sha256' => hash('sha256', 'fwd'),
            'stored_path' => 'docs/fwd.eml', 'kind' => 'po', 'work_job_id' => $job->id]);
        $this->offer($job);
        $this->gmail->down = true;
        $this->assertSame([['T2', 'enquiry']], array_map(fn ($c) => [$c['thread_id'], $c['how']], $this->threads()->candidates($this->invoiceFor($job), true)));
    }

    public function test_the_domain_of_an_address(): void
    {
        $this->assertSame('acme.example.test', BookingThread::domainOf('Acme Buyer <Buyer@ACME.example.test>'));
        $this->assertNull(BookingThread::domainOf('no address'));
        $this->assertNull(BookingThread::domainOf(null));
    }
}
