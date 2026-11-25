<?php

namespace Tests\Feature\Invoices;

use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Build slice S7: Expend's own drafts in Gmail. The draft is built as an email (To only, the reply headers Gmail needs
 * to keep it in the conversation, the PDF, the X-Expend-Ref header), and Gmail's drafts, threads and messages are read,
 * made, sent and deleted through GmailClient. Gmail is faked (FakeGmail): nothing here calls Google.
 */
class GmailDraftsTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmail $gmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gmail = FakeGmail::install();
    }

    private function mime(array $extra = []): string
    {
        return GmailDrafts::mime($extra + [
            'from' => 'alex@example.test', 'from_name' => 'Alex Smith Sound', 'to' => 'accounts@acme.example.test',
            'subject' => 'Re: PO 4471 · Arena load-in', 'in_reply_to' => 'last@acme.example.test', 'references' => ['first@acme.example.test', 'last@acme.example.test'],
            'ref' => '42/invoice/7531ebc0', 'text' => "Hello,\n\nPlease find attached invoice INV-0042.\n",
            'attachment' => ['name' => 'INV-0042.pdf', 'data' => '%PDF-1.4 pretend invoice'],
        ]);
    }

    public function test_the_draft_is_built_to_one_address_with_the_reply_headers_the_pdf_and_its_ref(): void
    {
        $raw = $this->mime();
        $message = (new MailMimeParser)->parse($raw, false);

        $this->assertSame('alex@example.test', $message->getHeaderValue('From'));
        $this->assertSame('Alex Smith Sound', $message->getHeader('From')->getPersonName());
        $this->assertSame('accounts@acme.example.test', $message->getHeaderValue('To'));
        $this->assertCount(1, $message->getHeader('To')->getAddresses(), 'To one address only');
        $this->assertNull($message->getHeader('Cc'));
        $this->assertNull($message->getHeader('Bcc'));
        $this->assertSame('Re: PO 4471 · Arena load-in', $message->getHeaderValue('Subject'));
        $this->assertStringContainsString('<last@acme.example.test>', $message->getHeader('In-Reply-To')->getRawValue());
        $this->assertSame('<first@acme.example.test> <last@acme.example.test>', trim((string) preg_replace('/\s+/', ' ', $message->getHeader('References')->getRawValue())));
        $this->assertSame('42/invoice/7531ebc0', $message->getHeaderValue(GmailDrafts::REF_HEADER));
        $this->assertSame("Hello,\n\nPlease find attached invoice INV-0042.\n", str_replace("\r\n", "\n", (string) $message->getTextContent()));
        $this->assertSame(1, $message->getAttachmentCount());
        $pdf = $message->getAttachmentPart(0);
        $this->assertSame(['INV-0042.pdf', 'application/pdf'], [$pdf->getFilename(), $pdf->getContentType()]);
        $this->assertSame(hash('sha256', '%PDF-1.4 pretend invoice'), hash('sha256', (string) $pdf->getContent()), 'The PDF exactly as stored');

        // No address: no To header at all (you add it in Gmail); a new email has no reply headers.
        $bare = (new MailMimeParser)->parse($this->mime(['to' => '', 'in_reply_to' => null, 'references' => []]), false);
        $this->assertNull($bare->getHeader('To'));
        $this->assertNull($bare->getHeader('In-Reply-To'));
        $this->assertNull($bare->getHeader('References'));
    }

    public function test_message_ids_are_read_from_headers(): void
    {
        $this->assertSame(['a@b.example', 'c@d.example'], GmailDrafts::ids('<a@b.example>  <c@d.example>'."\r\n ".'<a@b.example>'));
        $this->assertSame([], GmailDrafts::ids(null));
    }

    public function test_a_draft_is_made_in_the_conversation_read_sent_and_deleted(): void
    {
        $po = $this->gmail->receive('buyer@acme.example.test', 'PO 4471');
        $drafts = new GmailDrafts;
        $made = $drafts->create($this->mime(), $po['thread']);
        $this->assertSame($po['thread'], $made['thread_id']);
        $this->assertSame(['raw', 'threadId'], array_keys($this->gmail->created[0]['message']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $this->gmail->created[0]['message']['raw'], 'base64url, as Gmail takes it');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/drafts') && $r['message']['threadId'] === $po['thread']);

        // Read back: a version (its message id), headers by lower-case name, the PDF's name and the text.
        $draft = $drafts->get($made['id'], 'full');
        $this->assertSame([$made['id'], $made['message_id'], ['DRAFT']], [$draft['id'], $draft['message']['id'], $draft['message']['labels']]);
        $this->assertSame('42/invoice/7531ebc0', $draft['message']['headers']['x-expend-ref']);
        $this->assertSame(['INV-0042.pdf'], $draft['message']['files']);
        $this->assertStringContainsString('Please find attached invoice INV-0042.', $draft['message']['text']);
        $this->assertSame([$po['id'], $made['message_id']], array_column($drafts->thread($po['thread']), 'id'), 'Oldest first');

        // Sent: Gmail's message.
        $sent = $drafts->send($made['id']);
        $this->assertNull($drafts->get($made['id']), 'A sent draft isn’t there any more');
        $this->assertSame(['SENT'], $drafts->message($sent['message_id'])['labels']);

        // Deleted: true the first time, false once it's gone.
        $other = $drafts->create($this->mime(['ref' => '43/invoice/aaaaaaaa']), null);
        $this->assertTrue($drafts->delete($other['id']));
        $this->assertFalse($drafts->delete($other['id']));
        $this->assertNull($drafts->thread('no-such-thread'));
        $this->assertNull($drafts->message('no-such-message'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/drafts/'.$other['id']));
    }

    public function test_a_draft_an_earlier_try_made_is_found_by_its_ref(): void
    {
        $po = $this->gmail->receive('buyer@acme.example.test', 'PO 4471');
        $drafts = new GmailDrafts;
        $made = $drafts->create($this->mime(), $po['thread']);
        $this->assertSame(['id' => $made['id'], 'message_id' => $made['message_id'], 'thread_id' => $po['thread']],
            $drafts->findDraft($po['thread'], '42/invoice/7531ebc0', 'Re: PO 4471 · Arena load-in'));
        $this->assertNull($drafts->findDraft($po['thread'], '42/invoice/00000000', 'Re: PO 4471 · Arena load-in'), 'Another PDF: not this draft');
        $this->assertSame($made['id'], $drafts->draftIdOf($made['message_id']));

        // A new email: found among drafts with that subject.
        $new = $drafts->create($this->mime(['subject' => 'Invoice INV-0043 · Gala dinner', 'ref' => '43/invoice/bbbbbbbb', 'in_reply_to' => null, 'references' => []]), null);
        $this->assertSame($new['id'], $drafts->findDraft(null, '43/invoice/bbbbbbbb', 'Invoice INV-0043 · Gala dinner')['id']);
        $this->assertNull($drafts->findDraft(null, '43/invoice/cccccccc', 'Invoice INV-0043 · Gala dinner'));
        $this->assertTrue(collect($this->gmail->calls)->contains(fn (string $c) => str_contains(urldecode($c), 'q=subject:(Invoice INV-0043 Gala dinner)')));
    }

    public function test_threads_are_read_with_the_headers_expend_needs(): void
    {
        $po = $this->gmail->receive('buyer@acme.example.test', 'PO 4471');
        (new GmailDrafts)->thread($po['thread']);
        $call = collect($this->gmail->calls)->first(fn (string $c) => str_starts_with($c, 'GET threads/'));
        $this->assertStringContainsString('format=metadata', $call);
        foreach (GmailDrafts::HEADERS as $header) {
            $this->assertStringContainsString('metadataHeaders='.rawurlencode($header), $call, 'Each header asked for on its own');
        }
    }

    public function test_gmail_failures_carry_the_http_status_and_a_call_you_wait_on_isnt_retried(): void
    {
        Sleep::fake();
        $this->gmail->status = 404;
        try {
            GmailClient::get('drafts/r1');
            $this->fail('A refusal throws');
        } catch (RuntimeException $e) {
            $this->assertSame([404, 'Gmail said: HTTP 404'], [$e->getCode(), $e->getMessage()]);
        }

        $this->gmail->status = 503;
        $before = count($this->gmail->calls);
        try {
            GmailClient::get('drafts/r1', [], 10);
        } catch (RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
        }
        $this->assertSame(1, count($this->gmail->calls) - $before, 'With a time limit: one try');
        $before = count($this->gmail->calls);
        try {
            GmailClient::get('drafts/r1');
        } catch (RuntimeException) {
        }
        $this->assertSame(2, count($this->gmail->calls) - $before, 'Without: Google’s own errors are tried again');

        $this->gmail->status = 500;
        try {
            GmailClient::delete('drafts/r1');
        } catch (RuntimeException $e) {
            $this->assertSame(500, $e->getCode());
        }

        $this->gmail->status = null;
        $this->gmail->down = true;
        $this->expectException(ConnectionException::class);
        (new GmailDrafts(10))->get('r1');
    }
}
