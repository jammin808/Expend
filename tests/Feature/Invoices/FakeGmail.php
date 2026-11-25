<?php

namespace Tests\Feature\Invoices;

use App\Domain\Gmail\GmailClient;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * A pretend Gmail for the invoice email tests: conversations, drafts and sent mail kept in memory, answered through
 * Http::fake, so no test ever calls Google. It keeps every request (calls), can make the next drafts.create succeed in
 * Gmail and then time out, can stop answering (down) or answer every call with one status, and can do what you'd do in
 * Gmail yourself: edit a draft, delete it, or press Send.
 */
final class FakeGmail
{
    public const ACCOUNT = 'alex@example.test';

    /** @var array<string, array{id:string, threadId:string, labels:list<string>, at:int, headers:array<string,string>, text:string, files:array<string,string>, raw:?string}> */
    public array $messages = [];

    /** @var array<string, string> draft id => its message id */
    public array $drafts = [];

    /** @var list<string> "METHOD path?query" for each call */
    public array $calls = [];

    /** @var list<array> drafts.create bodies */
    public array $created = [];

    public bool $timeoutAfterCreate = false;

    public bool $down = false;

    public ?int $status = null;

    private int $n = 0;

    public static function install(): self
    {
        Setting::put('gmail_refresh_token', 'rt');
        Setting::put('gmail_access_token', 'at');
        Setting::put('gmail_access_expires', now()->addDays(30)->timestamp);
        Setting::put('gmail_scopes', [GmailClient::SCOPE]);
        Setting::put('gmail_account', self::ACCOUNT);
        Setting::put('gmail_label_invoiced', false); // the ✓ Invoiced label has its own tests
        $gmail = new self;
        Http::fake(fn (Request $request) => $gmail->answer($request));

        return $gmail;
    }

    /** An email you received, in a new conversation (or one given): its Gmail id, conversation and Message-ID. */
    public function receive(string $from, string $subject, ?string $thread = null, array $headers = [], ?int $at = null): array
    {
        $id = 'g'.(++$this->n);
        $thread ??= 't'.$this->n;
        $messageId = 'msg'.$this->n.'@'.rtrim(substr(strrchr($from, '@') ?: '@mail.example.test', 1), '>');
        $this->messages[$id] = ['id' => $id, 'threadId' => $thread, 'labels' => ['INBOX'], 'at' => $at ?? now()->subDays(10)->timestamp,
            'headers' => ['From' => $from, 'To' => self::ACCOUNT, 'Subject' => $subject, 'Message-ID' => '<'.$messageId.'>'] + $headers, 'text' => 'Hello', 'files' => [], 'raw' => null];

        return ['id' => $id, 'thread' => $thread, 'message_id' => $messageId];
    }

    /** The message a draft holds now. */
    public function draft(string $draftId): ?array
    {
        return isset($this->drafts[$draftId]) ? $this->messages[$this->drafts[$draftId]] : null;
    }

    /** You edit the draft in Gmail: it gets a new message id. */
    public function edit(string $draftId, array $headers = [], ?string $text = null): string
    {
        $old = $this->messages[$this->drafts[$draftId]];
        unset($this->messages[$old['id']]);
        $id = 'g'.(++$this->n);
        $this->messages[$id] = ['id' => $id, 'headers' => $headers + $old['headers'], 'text' => $text ?? $old['text']] + $old;
        $this->drafts[$draftId] = $id;

        return $id;
    }

    /** You delete the draft in Gmail. */
    public function deleteByHand(string $draftId): void
    {
        unset($this->messages[$this->drafts[$draftId]], $this->drafts[$draftId]);
    }

    /** You press Send in Gmail: the draft goes, a sent message (with Gmail's own Message-ID) takes its place. */
    public function sendByHand(string $draftId, bool $keepPdf = true): array
    {
        return $this->sendDraft($draftId, $keepPdf);
    }

    /** @return list<array> the messages sent */
    public function sent(): array
    {
        return array_values(array_filter($this->messages, fn ($m) => in_array('SENT', $m['labels'], true)));
    }

    public function answer(Request $request): mixed
    {
        $url = $request->url();
        $path = (string) preg_replace('#^https://gmail\.googleapis\.com/gmail/v1/users/me/#', '', (string) strtok($url, '?'));
        $query = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            if ($pair !== '') {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $query[urldecode($k)][] = urldecode($v);
            }
        }
        $this->calls[] = $request->method().' '.$path.(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : '');
        if (str_starts_with($url, 'https://oauth2.googleapis.com/token') && ! $this->down) {
            return Http::response(['access_token' => 'at2', 'expires_in' => 3600]);
        }
        if ($this->down) {
            return Http::failedConnection();
        }
        if ($this->status !== null) {
            return Http::response(['error' => ['message' => 'HTTP '.$this->status]], $this->status);
        }
        $format = $query['format'][0] ?? 'full';
        $method = $request->method();

        if ($method === 'POST' && $path === 'drafts') {
            return $this->create($request->data());
        }
        if ($method === 'POST' && $path === 'drafts/send') {
            $draftId = (string) ($request->data()['id'] ?? '');
            if (! isset($this->drafts[$draftId])) {
                return self::notFound();
            }
            $sent = $this->sendDraft($draftId, true);

            return Http::response(['id' => $sent['id'], 'threadId' => $sent['threadId'], 'labelIds' => $sent['labels']]);
        }
        if ($method === 'DELETE' && preg_match('#^drafts/([^/]+)$#', $path, $m)) {
            if (! isset($this->drafts[$m[1]])) {
                return self::notFound();
            }
            $this->deleteByHand($m[1]);

            return Http::response('', 204);
        }
        if ($method === 'GET' && $path === 'drafts') {
            $words = preg_match('/^subject:\((.*)\)$/', $query['q'][0] ?? '', $m) ? array_filter(explode(' ', mb_strtolower($m[1]))) : [];
            $list = [];
            foreach ($this->drafts as $id => $messageId) {
                $subject = mb_strtolower($this->messages[$messageId]['headers']['Subject'] ?? '');
                if (collect($words)->every(fn ($w) => str_contains($subject, $w))) {
                    $list[] = ['id' => $id, 'message' => ['id' => $messageId, 'threadId' => $this->messages[$messageId]['threadId']]];
                }
            }

            return Http::response($list === [] ? ['resultSizeEstimate' => 0] : ['drafts' => $list]);
        }
        if ($method === 'GET' && preg_match('#^drafts/([^/]+)$#', $path, $m)) {
            return isset($this->drafts[$m[1]]) ? Http::response(['id' => $m[1], 'message' => $this->api($this->messages[$this->drafts[$m[1]]], $format)]) : self::notFound();
        }
        if ($method === 'GET' && preg_match('#^threads/([^/]+)$#', $path, $m)) {
            $messages = array_values(array_filter($this->messages, fn ($msg) => $msg['threadId'] === $m[1]));

            return $messages === [] ? self::notFound() : Http::response(['id' => $m[1], 'messages' => array_map(fn ($msg) => $this->api($msg, $format), $messages)]);
        }
        if ($method === 'GET' && $path === 'messages') {
            // Two searches are faked: the Message-ID one (rfc822msgid:<id>), and sent mail with a file since a time
            // (in:sent filename:"INV-0001.pdf" after:<seconds>).
            $q = $query['q'][0] ?? '';
            if (preg_match('/^in:sent filename:"([^"]+)" after:(\d+)$/', $q, $m)) {
                $found = array_values(array_filter($this->messages, fn ($msg) => in_array('SENT', $msg['labels'], true) && isset($msg['files'][$m[1]]) && $msg['at'] >= (int) $m[2]));

                return Http::response(['messages' => array_map(fn ($msg) => ['id' => $msg['id'], 'threadId' => $msg['threadId']], $found)]);
            }
            $wanted = preg_match('/^rfc822msgid:(.+)$/', $q, $m) ? '<'.trim($m[1], '<>').'>' : null;
            $found = array_values(array_filter($this->messages, fn ($msg) => $wanted !== null && ($msg['headers']['Message-ID'] ?? null) === $wanted));

            return Http::response(['messages' => array_map(fn ($msg) => ['id' => $msg['id'], 'threadId' => $msg['threadId']], $found)]);
        }
        if ($method === 'GET' && preg_match('#^messages/([^/]+)$#', $path, $m)) {
            return isset($this->messages[$m[1]]) ? Http::response($this->api($this->messages[$m[1]], $format)) : self::notFound();
        }

        return Http::response(['error' => ['message' => 'Not faked: '.$method.' '.$path]], 400);
    }

    private function create(array $body): mixed
    {
        $this->created[] = $body;
        $raw = (string) base64_decode(strtr((string) ($body['message']['raw'] ?? ''), '-_', '+/'));
        $parsed = (new MailMimeParser)->parse($raw, false);
        $headers = [];
        foreach ($parsed->getAllHeaders() as $h) {
            // Gmail gives header values decoded (a Subject in =?utf-8?…?= words reads as typed), unfolded.
            $headers[$h->getName()] ??= $h->getName() === 'Subject' ? (string) $h->getValue() : (string) preg_replace('/\r?\n[ \t]+/', ' ', $h->getRawValue());
        }
        $files = [];
        foreach ($parsed->getAllAttachmentParts() as $part) {
            $files[(string) $part->getFilename()] = (string) $part->getContent();
        }
        $id = 'g'.(++$this->n);
        $thread = $body['message']['threadId'] ?? 't'.$this->n;
        $this->messages[$id] = ['id' => $id, 'threadId' => $thread, 'labels' => ['DRAFT'], 'at' => now()->timestamp, 'headers' => $headers,
            'text' => str_replace("\r\n", "\n", (string) $parsed->getTextContent()), 'files' => $files, 'raw' => $raw];
        $draftId = 'r'.$this->n;
        $this->drafts[$draftId] = $id;
        if ($this->timeoutAfterCreate) {
            $this->timeoutAfterCreate = false;

            return Http::failedConnection('Operation timed out after 10001 milliseconds');
        }

        return Http::response(['id' => $draftId, 'message' => ['id' => $id, 'threadId' => $thread, 'labelIds' => ['DRAFT']]]);
    }

    private function sendDraft(string $draftId, bool $keepPdf): array
    {
        $draft = $this->messages[$this->drafts[$draftId]];
        unset($this->messages[$draft['id']], $this->drafts[$draftId]);
        $id = 'g'.(++$this->n);
        $this->messages[$id] = ['id' => $id, 'labels' => ['SENT'], 'at' => now()->timestamp, 'files' => $keepPdf ? $draft['files'] : [],
            'headers' => ['Message-ID' => '<CAgmail'.$this->n.'@mail.gmail.com>'] + $draft['headers']] + $draft;

        return $this->messages[$id];
    }

    private function api(array $m, string $format): array
    {
        $payload = ['headers' => collect($m['headers'])->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all()];
        if ($format === 'full') {
            $payload['mimeType'] = 'multipart/mixed';
            $payload['parts'] = [['mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => rtrim(strtr(base64_encode($m['text']), '+/', '-_'), '=')]]];
            foreach ($m['files'] as $name => $data) {
                $payload['parts'][] = ['mimeType' => 'application/pdf', 'filename' => $name, 'body' => ['attachmentId' => 'a'.md5($name), 'size' => strlen($data)]];
            }
        }

        return ['id' => $m['id'], 'threadId' => $m['threadId'], 'labelIds' => $m['labels'], 'internalDate' => (string) ($m['at'] * 1000), 'payload' => $payload];
    }

    private static function notFound(): mixed
    {
        return Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.']], 404);
    }
}
