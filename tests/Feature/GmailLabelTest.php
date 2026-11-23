<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailLabeller;
use App\Domain\Invoices\InvoiceService;
use App\Models\Document;
use App\Models\GmailLabel;
use App\Models\GmailMessage;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailLabelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> batchModify bodies sent to Gmail */
    private array $modified = [];

    private array $searches = [];

    private array $threads = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addHour()->timestamp);
        Setting::put('gmail_scopes', [GmailClient::SCOPE]);
    }

    /** @param array<string, list<string>> $searches Gmail search text => message ids */
    private function fakeGmail(array $searches = [], array $threads = []): void
    {
        // Answers can change during a test; the fake is registered once and reads them each time.
        $this->searches = $searches;
        $this->threads = $threads;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            $searches = $this->searches;
            $threads = $this->threads;
            $url = urldecode($request->url());
            if (str_contains($url, '/labels') && $request->method() === 'GET') {
                return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'INBOX']]]);
            }
            if (str_contains($url, '/labels') && $request->method() === 'POST') {
                return Http::response(['id' => 'Label_9', 'name' => $request['name']]);
            }
            if (str_contains($url, 'batchModify')) {
                $this->modified[] = $request->data();

                return Http::response('', 204);
            }
            if (preg_match('#/messages/([^/?]+)\?format=minimal#', $url, $m)) {
                return Http::response(['id' => $m[1], 'threadId' => $threads[$m[1]] ?? $m[1]]);
            }
            foreach ($searches as $q => $ids) {
                if (str_contains($url, 'q='.$q)) {
                    return Http::response(['messages' => array_map(fn ($id) => ['id' => $id], $ids)]);
                }
            }

            return Http::response(['messages' => []]);
        });
    }

    private function jobWithPo(string $raw, string $source = 'email'): array
    {
        $job = WorkJob::query()->create(['title' => 'May show', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10']);
        $email = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', $source);
        $email->update(['kind' => 'po', 'work_job_id' => $job->id, 'extraction' => ['po' => ['po_number' => 'PO-7781']]]);

        return [$job, $email->fresh()];
    }

    private function issue(WorkJob $job): Invoice
    {
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 50000, 'bill_to_name' => 'Client']);
        $invoice->lines()->create(['description' => 'Show day', 'quantity' => 1, 'unit_price_minor' => 50000, 'amount_minor' => 50000]);

        return app(InvoiceService::class)->issue($invoice->fresh());
    }

    public function test_issuing_the_invoice_labels_the_po_email_from_gmail_and_voiding_takes_it_off(): void
    {
        $this->fakeGmail();
        [$job, $email] = $this->jobWithPo("From: buyer@client.example\r\nSubject: PO\r\n\r\nPO-7781\r\n", 'gmail');
        GmailMessage::query()->create(['message_id' => 'gm1', 'search' => 'job_documents', 'document_id' => $email->id]);

        $invoice = $this->issue($job); // the queue runs at once in tests

        $this->assertSame([['ids' => ['gm1'], 'addLabelIds' => ['Label_9']]], $this->modified);
        $this->assertSame('gmail_search', GmailLabel::query()->sole()->found_by);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/labels') && $r->method() === 'POST' && $r['name'] === '✓ Invoiced' && $r['color']['backgroundColor'] === '#16a766');

        app(GmailLabeller::class)->sync();
        $this->assertCount(1, $this->modified, 'Already labelled: nothing sent again');

        app(InvoiceService::class)->void($invoice->fresh(), 'Wrong amount');
        $this->assertSame(['ids' => ['gm1'], 'removeLabelIds' => ['Label_9']], $this->modified[1]);
        $this->assertSame(0, GmailLabel::query()->count());
    }

    public function test_a_forwarded_po_is_found_in_gmail_by_its_message_id(): void
    {
        $this->fakeGmail(['rfc822msgid:abc123@client.example' => ['orig1']]);
        [$job] = $this->jobWithPo("From: buyer@client.example\r\nMessage-ID: <abc123@client.example>\r\nSubject: PO\r\n\r\nPO-7781\r\n");

        $this->issue($job);

        $this->assertSame(['orig1'], $this->modified[0]['ids']);
        $this->assertSame('message_id', GmailLabel::query()->sole()->found_by);
    }

    public function test_the_po_number_search_only_labels_when_every_match_is_in_one_conversation(): void
    {
        $search = '"PO-7781" -in:sent -in:drafts -in:chats';
        $this->fakeGmail([$search => ['a1', 'a2']], ['a1' => 'T1', 'a2' => 'T1']);
        $job = WorkJob::query()->create(['title' => 'Phone PO', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10', 'po_number' => 'PO-7781']);
        $this->issue($job);
        $this->assertSame(['a1', 'a2'], $this->modified[0]['ids']);

        $this->modified = [];
        GmailLabel::query()->delete();
        $this->fakeGmail([$search => ['a1', 'b1']], ['a1' => 'T1', 'b1' => 'T2']);
        $result = app(GmailLabeller::class)->sync();
        $this->assertSame([], $this->modified, 'Two different conversations mention it: no guessing');
        $this->assertSame(['Phone PO'], $result['missing']);
    }

    public function test_nothing_is_changed_with_a_read_only_connection_or_with_the_label_switched_off(): void
    {
        $this->fakeGmail();
        [$job, $email] = $this->jobWithPo("From: b@c.example\r\nSubject: PO\r\n\r\nx\r\n", 'gmail');
        GmailMessage::query()->create(['message_id' => 'gm1', 'search' => 'job_documents', 'document_id' => $email->id]);

        Setting::put('gmail_scopes', [GmailClient::READONLY]);
        $this->issue($job);
        app(GmailLabeller::class)->sync();
        Setting::put('gmail_scopes', [GmailClient::SCOPE]);
        Setting::put('gmail_label_invoiced', false);
        app(GmailLabeller::class)->sync();

        $this->assertSame([], $this->modified);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_connecting_asks_for_permission_to_change_labels(): void
    {
        Setting::put('google_client_id', 'abc.apps.googleusercontent.com');
        parse_str(parse_url(GmailClient::authUrl(), PHP_URL_QUERY), $q);
        $this->assertSame('https://www.googleapis.com/auth/gmail.modify', $q['scope']);
        $this->assertTrue(GmailClient::canModify());
        $this->assertInstanceOf(Document::class, new Document);
    }
}
