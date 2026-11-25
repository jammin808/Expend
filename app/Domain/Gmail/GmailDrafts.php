<?php

namespace App\Domain\Gmail;

use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\IdentificationHeader;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Expend's own drafts in your Gmail: an issued invoice, or a reminder you asked for, written as a draft in the booking's
 * conversation, for you to send (from Gmail, or with Send in Expend).
 *
 * A draft is built here (mime()): From your connected Gmail, To one address only (or none, for you to add in Gmail;
 * never Cc, never reply-all), the subject, In-Reply-To and References pointing to the conversation's last message (Gmail
 * keeps a draft in a conversation only when it's given the threadId, those two headers and a matching subject), the
 * text, the PDF, and an X-Expend-Ref header naming what it is (invoice id, kind, the PDF's sha256 start), so a retry
 * finds the draft an earlier try made instead of making a second one (findDraft()).
 *
 * Gmail's API: drafts.create (with threadId), drafts.get, drafts.list, drafts.send, drafts.delete (Expend's own drafts
 * you haven't edited only: the caller checks), threads.get and messages.get. A draft or conversation that isn't there
 * any more comes back as null, never as an error. Each call can be given a time limit (seconds), for a call made while
 * you wait.
 */
final class GmailDrafts
{
    public const REF_HEADER = 'X-Expend-Ref';

    /** The headers read from a conversation's messages (threads.get with format metadata). */
    public const HEADERS = ['Subject', 'From', 'To', 'Cc', 'Date', 'Message-ID', 'In-Reply-To', 'References', self::REF_HEADER];

    /** Drafts looked through when finding one by its message (drafts.list pages of 100). */
    private const LIST_PAGES = 5;

    public function __construct(private ?int $timeout = null) {}

    /** The same, with a time limit for each call (seconds; null: Gmail's usual 30, with retries). */
    public function withTimeout(?int $seconds): self
    {
        return new self($seconds);
    }

    /**
     * The draft as an email (RFC 2822), ready for drafts.create.
     *
     * @param  array{from:string, from_name?:?string, to?:?string, subject:string, in_reply_to?:?string, references?:list<string>,
     *     ref:string, text:string, attachment?:?array{name:string, data:string, mime?:string}}  $m
     *                                                                                               ids without their angle brackets
     */
    public static function mime(array $m): string
    {
        $headers = new Headers;
        $headers->addMailboxListHeader('From', [new Address($m['from'], (string) ($m['from_name'] ?? ''))]);
        $to = trim((string) ($m['to'] ?? ''));
        if ($to !== '') {
            $headers->addMailboxListHeader('To', [new Address($to)]);
        }
        $headers->addTextHeader('Subject', $m['subject']);
        // A conversation's ids as other mail programs wrote them: one that isn't a valid Message-ID (no "@", a
        // doubled dot) is left out rather than stopping the draft.
        if (($m['in_reply_to'] ?? null) !== null && $m['in_reply_to'] !== '' && self::validId($m['in_reply_to'])) {
            $headers->addIdHeader('In-Reply-To', $m['in_reply_to']);
        }
        $references = array_values(array_filter($m['references'] ?? [], fn ($id) => is_string($id) && $id !== '' && self::validId($id)));
        if ($references !== []) {
            $headers->addIdHeader('References', $references);
        }
        $headers->addTextHeader(self::REF_HEADER, $m['ref']);

        $text = new TextPart($m['text'], 'utf-8', 'plain', 'quoted-printable');
        $body = isset($m['attachment'])
            ? new MixedPart($text, new DataPart($m['attachment']['data'], $m['attachment']['name'], $m['attachment']['mime'] ?? 'application/pdf'))
            : $text;

        return (new Message($headers, $body))->toString();
    }

    /** Whether a Message-ID (without its angle brackets) can be written in an id header. */
    public static function validId(string $id): bool
    {
        try {
            new IdentificationHeader('References', $id);

            return true;
        } catch (RfcComplianceException) {
            return false;
        }
    }

    /** Message-IDs as written in References and In-Reply-To: "<a@b> <c@d>" gives ['a@b', 'c@d']. */
    public static function ids(?string $header): array
    {
        preg_match_all('/<([^<>\s]+)>/', (string) $header, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Make the draft, in the conversation when given.
     *
     * @return array{id:string, message_id:string, thread_id:?string}
     */
    public function create(string $raw, ?string $threadId): array
    {
        $message = ['raw' => self::base64url($raw)] + ($threadId !== null && $threadId !== '' ? ['threadId' => $threadId] : []);
        $draft = GmailClient::post('drafts', ['message' => $message], $this->timeout);
        if (! isset($draft['id'], $draft['message']['id'])) {
            throw new RuntimeException('Gmail didn’t say which draft it made.');
        }

        return ['id' => (string) $draft['id'], 'message_id' => (string) $draft['message']['id'], 'thread_id' => $draft['message']['threadId'] ?? $threadId];
    }

    /**
     * A draft as it is now in Gmail, or null when it isn't there (sent or deleted). Its message id changes whenever it's
     * edited, so it's the draft's version.
     *
     * @param  string  $format  metadata (headers only) or full (with the text and the attachments' names)
     * @return array{id:string, message:array}|null the message as message() gives it
     */
    public function get(string $draftId, string $format = 'metadata'): ?array
    {
        $draft = $this->orNull(fn () => GmailClient::get('drafts/'.rawurlencode($draftId), self::query($format), $this->timeout));
        if ($draft === null || ! isset($draft['message']['id'])) {
            return null;
        }

        return ['id' => (string) $draft['id'], 'message' => self::normalise($draft['message'])];
    }

    /**
     * Send a draft as it is now (drafts.send).
     *
     * @return array{message_id:string, thread_id:?string}
     */
    public function send(string $draftId): array
    {
        $sent = GmailClient::post('drafts/send', ['id' => $draftId], $this->timeout);
        if (! isset($sent['id'])) {
            throw new RuntimeException('Gmail didn’t say which message it sent.');
        }

        return ['message_id' => (string) $sent['id'], 'thread_id' => $sent['threadId'] ?? null];
    }

    /** Delete a draft (only one of Expend's own you haven't edited: the caller checks). False when it was already gone. */
    public function delete(string $draftId): bool
    {
        return $this->orNull(function () use ($draftId) {
            GmailClient::delete('drafts/'.rawurlencode($draftId), $this->timeout);

            return true;
        }) ?? false;
    }

    /**
     * A conversation's messages, oldest first, or null when it isn't there.
     *
     * @return list<array>|null each as normalise() gives it
     */
    public function thread(string $threadId, string $format = 'metadata'): ?array
    {
        $thread = $this->orNull(fn () => GmailClient::get('threads/'.rawurlencode($threadId), self::query($format), $this->timeout));
        if ($thread === null) {
            return null;
        }
        $messages = array_map(fn (array $m) => self::normalise($m), $thread['messages'] ?? []);
        usort($messages, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $messages;
    }

    /** One message, or null when it isn't there. */
    public function message(string $messageId, string $format = 'metadata'): ?array
    {
        $message = $this->orNull(fn () => GmailClient::get('messages/'.rawurlencode($messageId), self::query($format), $this->timeout));

        return $message === null ? null : self::normalise($message);
    }

    /**
     * A draft that already carries this X-Expend-Ref, so a retry adopts what an earlier try made: looked for in the
     * conversation (threads.get, unless its messages are given), or, for a new email, among drafts whose subject matches.
     * In the conversation, a draft with no X-Expend-Ref at all that carries $pdf counts too (one you edited in Gmail,
     * whose editor dropped the header), so a retry never makes a second draft beside yours.
     *
     * @param  list<array>|null  $messages  the conversation's messages as thread() gives them (format full), when already read
     * @return array{id:string, message_id:string, thread_id:?string}|null
     */
    public function findDraft(?string $threadId, string $ref, string $subject, ?array $messages = null, ?string $pdf = null): ?array
    {
        if ($threadId !== null && $threadId !== '') {
            $all = collect($messages ?? $this->thread($threadId, 'full') ?? [])->filter(fn (array $m) => in_array('DRAFT', $m['labels'], true));
            $found = $all->first(fn (array $m) => ($m['headers'][strtolower(self::REF_HEADER)] ?? null) === $ref)
                ?? ($pdf === null ? null : $all->first(fn (array $m) => ! isset($m['headers'][strtolower(self::REF_HEADER)]) && self::carries($m, $pdf)));
            if ($found === null) {
                return null;
            }
            $draftId = $this->draftIdOf($found['id']);

            return $draftId === null ? null : ['id' => $draftId, 'message_id' => $found['id'], 'thread_id' => $found['thread_id'] ?? $threadId];
        }

        $words = trim((string) preg_replace(['/[^\pL\pN\- ]+/u', '/\s+/'], ' ', $subject));
        $list = GmailClient::get('drafts', http_build_query(['q' => 'subject:('.$words.')', 'maxResults' => 10]), $this->timeout);
        foreach (array_slice($list['drafts'] ?? [], 0, 10) as $d) {
            $draft = $this->get((string) $d['id']);
            if ($draft !== null && ($draft['message']['headers'][strtolower(self::REF_HEADER)] ?? null) === $ref) {
                return ['id' => $draft['id'], 'message_id' => $draft['message']['id'], 'thread_id' => $draft['message']['thread_id']];
            }
        }

        return null;
    }

    /**
     * A message you sent (not a draft) since $after (seconds) carrying $pdf, and when $ref is given that X-Expend-Ref
     * too (a reminder carries the invoice's PDF as the invoice does): in the conversation (unless its messages are
     * given), then, when $search, anywhere in your sent mail (a draft whose subject you changed is sent in a new
     * conversation). One sent with the PDF taken off isn't it: then you're asked. Null when there's none.
     *
     * @param  list<array>|null  $messages  the conversation's messages as thread() gives them (format full), when already read
     * @return array|null the message as normalise() gives it
     */
    public function findSent(?string $threadId, string $pdf, ?string $ref, int $after, ?array $messages = null, bool $search = true): ?array
    {
        $sent = fn (array $m) => in_array('SENT', $m['labels'], true) && ! in_array('DRAFT', $m['labels'], true) && $m['at'] >= $after
            && self::carries($m, $pdf) && ($ref === null || ($m['headers'][strtolower(self::REF_HEADER)] ?? null) === $ref);
        if ($threadId !== null && $threadId !== '') {
            $found = collect($messages ?? $this->thread($threadId, 'full') ?? [])->first($sent);
            if ($found !== null) {
                return $found;
            }
        }
        if (! $search) {
            return null;
        }
        $list = GmailClient::get('messages', http_build_query(['q' => 'in:sent filename:"'.$pdf.'" after:'.max(0, $after), 'maxResults' => 10]), $this->timeout);
        foreach (array_slice($list['messages'] ?? [], 0, 10) as $hit) {
            $m = $this->message((string) ($hit['id'] ?? ''), 'full');
            if ($m !== null && $sent($m)) {
                return $m;
            }
        }

        return null;
    }

    /** Whether a message (format full) has this file attached. */
    public static function carries(array $message, string $file): bool
    {
        return in_array(strtolower($file), array_map('strtolower', $message['files'] ?? []), true);
    }

    /** Which draft holds this message (drafts.list gives each draft's message id). */
    public function draftIdOf(string $messageId): ?string
    {
        $page = null;
        for ($i = 0; $i < self::LIST_PAGES; $i++) {
            $list = GmailClient::get('drafts', http_build_query(array_filter(['maxResults' => 100, 'pageToken' => $page])), $this->timeout);
            foreach ($list['drafts'] ?? [] as $d) {
                if (($d['message']['id'] ?? null) === $messageId) {
                    return (string) $d['id'];
                }
            }
            if (! ($page = $list['nextPageToken'] ?? null)) {
                break;
            }
        }

        return null;
    }

    /**
     * A message as Gmail gives it, flattened: its id and conversation, labels, when Gmail has it (seconds), headers by
     * lower-case name, the attachments' file names, and its plain text (format full only).
     *
     * @return array{id:string, thread_id:?string, labels:list<string>, at:int, headers:array<string,string>, files:list<string>, text:?string}
     */
    public static function normalise(array $message): array
    {
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $h) {
            $name = strtolower((string) ($h['name'] ?? ''));
            $headers[$name] ??= (string) ($h['value'] ?? '');
        }
        $files = [];
        $text = null;
        $walk = function (array $part) use (&$walk, &$files, &$text) {
            if (($part['filename'] ?? '') !== '') {
                $files[] = (string) $part['filename'];
            } elseif (($part['mimeType'] ?? '') === 'text/plain' && $text === null && isset($part['body']['data'])) {
                $text = (string) base64_decode(strtr((string) $part['body']['data'], '-_', '+/'));
            }
            foreach ($part['parts'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($message['payload'] ?? []);

        return [
            'id' => (string) ($message['id'] ?? ''), 'thread_id' => $message['threadId'] ?? null, 'labels' => array_values($message['labelIds'] ?? []),
            'at' => isset($message['internalDate']) ? intdiv((int) $message['internalDate'], 1000) : 0, 'headers' => $headers, 'files' => $files, 'text' => $text,
        ];
    }

    /** The query for a read: format, and for metadata the headers Expend reads (Gmail takes the parameter once each). */
    private static function query(string $format): string
    {
        $query = 'format='.rawurlencode($format);
        if ($format === 'metadata') {
            foreach (self::HEADERS as $h) {
                $query .= '&metadataHeaders='.rawurlencode($h);
            }
        }

        return $query;
    }

    /**
     * @template T
     *
     * @param  callable():T  $call
     * @return T|null null when Gmail says it isn't there (404)
     */
    private function orNull(callable $call): mixed
    {
        try {
            return $call();
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
