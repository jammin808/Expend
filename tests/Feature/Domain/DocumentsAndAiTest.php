<?php

namespace Tests\Feature\Domain;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Ai\DocumentReader;
use App\Domain\Ai\TransactionAdvisor;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Grounding;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Mail\MailboxFetcher;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Document;
use App\Models\Setting;
use App\Models\WorkJob;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class DocumentsAndAiTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('anthropic_api_key', 'test-key');
        Setting::put('ai_extraction', 'on_demand');
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    private function poPdf(): string
    {
        $pdf = new Dompdf;
        $pdf->loadHtml('<h1>Purchase Order PO-4512</h1><p>Acme Events Ltd, 1 High Street, Leeds LS1 1AA</p>'
            .'<p>Event: Product launch, Leeds Arena, 14 May 2025. Call time 07:30. Hotel: Premier Inn Leeds City, booked by Acme.</p>'
            .'<table><tr><td>Show day technician</td><td>2 x 450.00</td><td>900.00</td></tr><tr><td>Prep day</td><td>1 x 300.00</td><td>300.00</td></tr></table>'
            .'<p>Total 1,200.00 GBP. Payment terms: 30 days.</p>');
        $pdf->render();

        return $pdf->output();
    }

    private function poExtraction(int $jobId): array
    {
        return [
            'kind' => 'po', 'summary' => 'PO-4512 from Acme Events for the Leeds product launch.',
            'job_link' => ['job_id' => $jobId, 'reason' => 'Same client and date'],
            'new_job' => null,
            'facts' => [
                ['field' => 'call_time', 'label' => 'Call time', 'value' => '07:30', 'date' => '2025-05-14', 'time' => '07:30', 'quote' => 'Call time 07:30', 'page' => 1],
                ['field' => 'accommodation', 'label' => 'Hotel', 'value' => 'Premier Inn Leeds City, booked by Acme', 'date' => null, 'time' => null, 'quote' => 'Hotel: Premier Inn Leeds City, booked by Acme', 'page' => 1],
                ['field' => 'parking', 'label' => 'Parking', 'value' => 'Free parking in car park B', 'date' => null, 'time' => null, 'quote' => 'Free parking in car park B', 'page' => 1],
            ],
            'po' => [
                'po_number' => 'PO-4512', 'bill_to_name' => 'Acme Events Ltd', 'bill_to_address' => '1 High Street, Leeds LS1 1AA', 'bill_to_email' => null,
                'currency' => 'GBP', 'payment_terms_days' => 30, 'total' => ['amount_minor' => 120000, 'quote' => 'Total 1,200.00 GBP'],
                'lines' => [
                    ['description' => 'Show day technician', 'quantity' => 2, 'unit_price_minor' => 45000, 'amount_minor' => 90000, 'quote' => 'Show day technician 2 x 450.00 900.00'],
                    ['description' => 'Prep day', 'quantity' => 1, 'unit_price_minor' => 30000, 'amount_minor' => 30000, 'quote' => 'Prep day 1 x 300.00 300.00'],
                ],
            ],
            'receipt' => null,
        ];
    }

    public function test_grounding_matches_quotes_loosely_but_never_invents(): void
    {
        $text = "Call time:   07:30\nLoad–in from 06:00";
        $this->assertTrue(Grounding::quoteFound($text, 'call time 07:30'));
        $this->assertTrue(Grounding::quoteFound($text, 'Load-in from 06:00'));
        $this->assertFalse(Grounding::quoteFound($text, 'Call time 08:30'));
        $this->assertSame([120000, 30], Grounding::amountsIn('Total 1,200.00 and 0.30'));
        $this->assertTrue(Grounding::amountInQuote(90000, '2 x 450.00 900.00'));
        $this->assertFalse(Grounding::amountInQuote(95000, '2 x 450.00 900.00'));
    }

    public function test_a_po_is_read_with_every_detail_checked_against_the_document(): void
    {
        $client = Client::create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::create(['title' => 'Product launch', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $doc = app(DocumentIntake::class)->store($this->poPdf(), 'PO-4512.pdf', 'application/pdf', 'upload');
        $this->assertSame('extracted', $doc->text_status);

        $extraction = $this->poExtraction($job->id);
        $extraction['job_link']['job_id'] = 999; // not a candidate: must be ignored
        $this->ai->responses = [FakeAiClient::json($extraction)];
        $doc = app(DocumentReader::class)->read($doc);

        $this->assertSame('po', $doc->kind);
        $this->assertNull($doc->work_job_id, 'Links only to jobs the app offered');
        $facts = $doc->facts->keyBy('field');
        $this->assertTrue($facts['call_time']->verified);
        $this->assertTrue($facts['accommodation']->verified);
        $this->assertFalse($facts['parking']->verified, 'A quote that is not in the document is never verified');
        $this->assertSame(120000, $doc->extraction['po']['lines_total_minor']);
        $this->assertTrue($doc->extraction['po']['lines_match_total']);
        $this->assertTrue(collect($doc->extraction['po']['lines'])->every('verified'));
        $this->assertSame('document', $this->ai->requests[0]['messages'][0]['content'][0]['type']);
    }

    public function test_a_po_becomes_a_draft_invoice_then_an_issued_locked_invoice_and_its_payment_is_matched(): void
    {
        $client = Client::create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::create(['title' => 'Product launch', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $doc = app(DocumentIntake::class)->store($this->poPdf(), 'PO-4512.pdf', 'application/pdf', 'upload');
        $this->ai->responses = [FakeAiClient::json($this->poExtraction($job->id))];
        $doc = app(DocumentReader::class)->read($doc);
        $this->assertSame($job->id, $doc->work_job_id);

        Setting::put('business_name', 'Test Freelancer');
        $service = app(InvoiceService::class);
        $invoice = $service->draftFromDocument($doc);
        $this->assertSame(120000, $invoice->total_minor);
        $this->assertSame(120000, $invoice->po_total_minor);
        $this->assertSame('PO-4512', $invoice->po_number);
        $this->assertCount(2, $invoice->lines);

        $issued = $service->issue($invoice, '2025-05-20');
        $this->assertSame('INV-0001', $issued->number);
        $this->assertSame('2025-06-19', $issued->due_date->format('Y-m-d'));
        Storage::disk('local')->assertExists($issued->pdf_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($issued->pdf_path)), $issued->pdf_sha256);

        $this->expectException(\LogicException::class);
        try {
            $issued->update(['total_minor' => 1]);
        } finally {
            $second = $service->issue($service->draftForJob($job->fresh('client')->forceFill(['fee_pence' => 50000])));
            $this->assertSame('INV-0002', $second->number);

            $bank = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
            $path = storage_path('framework/pay.csv');
            file_put_contents($path, "Date,Counter Party,Reference,Type,Amount (GBP),Balance (GBP),Spending Category,Notes\n10/06/2025,Acme Events Ltd,PO-4512,FASTER PAYMENT,1200.00,2200.00,INCOME,\n");
            app(Importer::class)->commit($bank, $path, 'pay.csv', ProfileDetector::detect(CsvReader::read($path)['headers']));
            @unlink($path);
            $payment = BankTransaction::first()->allocations()->first();
            $this->assertSame($issued->id, $payment->invoice_id);
            $this->assertSame($job->id, $payment->work_job_id);
            $this->assertNull($payment->confirmed_at, 'Payment matching is a suggestion');
        }
    }

    public function test_emails_are_unpacked_into_attachments(): void
    {
        $boundary = 'XYZ';
        $pdf = base64_encode($this->poPdf());
        $eml = "From: Booker <booker@acme.example>\r\nTo: ledger@example.com\r\nSubject: Booking + PO for 14 May\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n"
            ."--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHi, please find the PO attached. Call 07:30.\r\n"
            ."--{$boundary}\r\nContent-Type: application/pdf; name=\"PO-4512.pdf\"\r\nContent-Disposition: attachment; filename=\"PO-4512.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split($pdf)."\r\n--{$boundary}--\r\n";

        $doc = app(DocumentIntake::class)->store($eml, 'booking.eml', 'message/rfc822', 'email');
        $this->assertSame('email', $doc->kind);
        $this->assertStringContainsString('Call 07:30', $doc->text);
        $this->assertSame(1, $doc->children()->count());
        $this->assertSame('PO-4512.pdf', $doc->children()->first()->original_name);
        $this->assertSame('extracted', $doc->children()->first()->text_status);

        // The same email again is not duplicated.
        app(DocumentIntake::class)->store($eml, 'booking.eml', 'message/rfc822', 'email');
        $this->assertSame(2, Document::count());
    }

    public function test_the_assistant_answers_from_tools_and_flags_figures_it_cannot_find(): void
    {
        $bank = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($bank, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers']));

        $this->ai->responses = [
            FakeAiClient::tool('search_transactions', ['query' => 'adobe']),
            FakeAiClient::text("You paid Adobe £56.98 (transaction 1). Over the year that's about £700.00.\nSuggestion: an estimated £60.00 a month covers it."),
        ];
        $result = app(Assistant::class)->ask('What do I pay Adobe?');

        $this->assertSame(['£700.00'], $result['unmatched']);
        $this->assertSame('search_transactions', $result['tools'][0]['tool']);
        $this->assertSame('tool_result', $this->ai->requests[1]['messages'][2]['content'][0]['type']);
    }

    public function test_ai_proposals_never_override_your_decisions(): void
    {
        $bank = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($bank, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers']));
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        app(AllocationWriter::class)->decide($adobe, AllocationWriter::lines($adobe->amount_pence, 'personal'));

        $this->ai->responses = [
            FakeAiClient::tool('propose_decision', ['transaction_id' => $adobe->id, 'kind' => 'business_expense', 'category_code' => 'software', 'reason' => 'Software']),
            FakeAiClient::text('Done.'),
        ];
        app(Assistant::class)->ask('Make Adobe a business expense');
        $this->assertSame('personal', $adobe->allocations()->value('kind'));
    }

    public function test_ai_suggestions_are_validated_and_saved_unconfirmed(): void
    {
        $bank = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($bank, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers']));
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $pret = BankTransaction::where('merchant_key', 'PRET MANGER')->get();
        $tfl = BankTransaction::where('merchant_key', 'TFL')->first();

        $this->ai->responses = [FakeAiClient::json(['suggestions' => [
            ['id' => $adobe->id, 'kind' => 'business_expense', 'category_code' => 'software', 'job_id' => 777, 'business_pct' => 100, 'reason' => 'Creative software'],
            ['id' => $pret[0]->id, 'kind' => 'business_expense', 'category_code' => 'turnover', 'job_id' => null, 'business_pct' => 100, 'reason' => 'Wrong kind of category'],
            ['id' => $pret[1]->id, 'kind' => 'unsure', 'category_code' => null, 'job_id' => null, 'business_pct' => 100, 'reason' => '?'],
            ['id' => $tfl->id, 'kind' => 'business_expense', 'category_code' => 'made_up_code', 'job_id' => null, 'business_pct' => 100, 'reason' => 'x'],
        ]])];
        $result = app(TransactionAdvisor::class)->suggest();

        $this->assertSame(1, $result['suggested']);
        $a = $adobe->allocations()->first();
        $this->assertSame('ai', $a->decided_by);
        $this->assertNull($a->work_job_id, 'Invented job ids are dropped');
        $this->assertNull($a->confirmed_at);
        $this->assertSame('unreviewed', $pret[0]->fresh()->review_status);
    }

    public function test_mail_from_unknown_or_unverified_senders_is_held(): void
    {
        Setting::put('allowed_senders', ['me@example.com']);
        $this->assertNull(MailboxFetcher::holdReason('me@example.com', "Authentication-Results: mx.example; dmarc=pass header.from=example.com\r\n"));
        $this->assertNotNull(MailboxFetcher::holdReason('someone@else.com', ''));
        $this->assertNotNull(MailboxFetcher::holdReason('me@example.com', "Authentication-Results: mx.example; spf=fail; dmarc=fail\r\n"));
    }

    public function test_sender_checks_read_folded_headers_and_need_the_senders_own_domain(): void
    {
        Setting::put('allowed_senders', ['me@example.com', 'jo.bloggs@gmail.com']);
        $folded = "Authentication-Results: mx.hostinger.com;\r\n\tdkim=pass header.d=example.com;\r\n\tspf=pass smtp.mailfrom=me@example.com\r\n";
        $this->assertNull(MailboxFetcher::holdReason('me@example.com', $folded));

        // Someone else's server can pass SPF and DKIM for its own domain while pretending to be you.
        $spoof = "Authentication-Results: mx.hostinger.com; spf=pass smtp.mailfrom=bounce@attacker.net; dkim=pass header.d=attacker.net; dmarc=fail header.from=gmail.com\r\n";
        $this->assertNotNull(MailboxFetcher::holdReason('jo.bloggs@gmail.com', $spoof));

        // Gmail ignores dots in addresses.
        $this->assertNull(MailboxFetcher::holdReason('jobloggs@gmail.com', "Authentication-Results: mx; dmarc=pass header.from=gmail.com\r\n"));
    }

    public function test_gmail_auto_forwarding_from_your_own_account_is_taken_in(): void
    {
        Setting::put('allowed_senders', ['jo.bloggs@gmail.com']);
        $forwarded = "Return-Path: <jo.bloggs+caf_=ledger=example.com@gmail.com>\r\n"
            ."Authentication-Results: mx.hostinger.com;\r\n spf=pass (sender is permitted) smtp.mailfrom=jo.bloggs+caf_=ledger=example.com@gmail.com;\r\n dkim=pass header.d=client.co.uk\r\n";
        $this->assertNull(MailboxFetcher::holdReason('buyer@client.co.uk', $forwarded), 'A PO auto-forwarded by your own Gmail');

        $this->assertNotNull(MailboxFetcher::holdReason('buyer@client.co.uk', str_replace('spf=pass', 'spf=fail', $forwarded)), 'SPF must pass');
        $this->assertNotNull(MailboxFetcher::holdReason('buyer@client.co.uk', str_replace('jo.bloggs+caf_', 'someone.else+caf_', $forwarded)), 'Someone else\'s Gmail');
        $this->assertNotNull(MailboxFetcher::holdReason('buyer@client.co.uk', str_replace('@gmail.com>', '@gmail.com.evil.net>', $forwarded)), 'Look-alike domain');
        $this->assertNotNull(MailboxFetcher::holdReason('buyer@client.co.uk', "Authentication-Results: mx; dmarc=pass\r\n"), 'Not forwarded at all');
    }
}
