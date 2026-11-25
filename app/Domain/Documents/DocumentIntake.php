<?php

namespace App\Domain\Documents;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\Opportunities;
use App\Domain\Jobs\OpportunityRules;
use App\Jobs\ReadDocument;
use App\Models\Document;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * The single way documents enter the app, whether uploaded, emailed or sent by Telegram.
 * Stores the original, extracts text, unpacks email attachments, ignores exact duplicates,
 * and queues the AI read when AI reading is switched on. An email is also checked for work it may
 * offer (an availability check, a hold, an offer): read with AI when that's on, otherwise spotted
 * by its words (OpportunityRules). Either way it's only proposed, never made a job.
 */
final class DocumentIntake
{
    public const MAX_BYTES = 30 * 1024 * 1024;

    /** Sources that bring documents in without you handing each one over: a Gmail search, the mailbox. */
    private const ARRIVES_BY_ITSELF = ['gmail', 'email'];

    /**
     * Keep a document. Besides who it's from and when, $meta may say what kind it is when you said so ('kind': a
     * receipt from the phone, rather than 'unknown' until it's read), and 'read' => false when the caller queues the
     * AI read itself.
     *
     * @throws \RuntimeException when the file couldn't be written (nothing is kept then)
     */
    public function store(string $contents, string $name, ?string $mime, string $source, array $meta = [], ?int $parentId = null): Document
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That file is over 30 MB.');
        }

        $sha = hash('sha256', $contents);
        if ($existing = Document::query()->where('sha256', $sha)->first()) {
            return $existing;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $mime = $mime ?: self::guessMime($ext);
        $path = "documents/{$sha}.".preg_replace('/[^a-z0-9]/', '', $ext);
        // The local disk doesn't throw when a write fails: a document is never kept pointing at a file that isn't there.
        if (! Storage::disk('local')->put($path, $contents) || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('The file couldn’t be saved just now. Try again.');
        }

        $isEmail = $ext === 'eml' || $mime === 'message/rfc822';
        $document = Document::query()->create([
            'parent_id' => $parentId,
            'source' => $source,
            'original_name' => mb_substr($name, 0, 250),
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => $sha,
            'stored_path' => $path,
            'email_from' => isset($meta['from']) ? mb_substr($meta['from'], 0, 250) : null,
            'email_subject' => isset($meta['subject']) ? mb_substr($meta['subject'], 0, 500) : null,
            'received_at' => $meta['received_at'] ?? now(),
            'kind' => $isEmail ? 'email' : (isset($meta['kind'], Document::KINDS[$meta['kind']]) ? $meta['kind'] : 'unknown'),
            'status' => ($meta['held'] ?? false) ? 'held' : 'new',
            'error' => $meta['held_reason'] ?? null,
            'work_job_id' => $meta['work_job_id'] ?? null,
            'job_linked_by' => ($meta['work_job_id'] ?? null) ? ($meta['job_linked_by'] ?? 'you') : null,
        ]);

        if ($isEmail) {
            $this->unpackEmail($document, $contents);
        } else {
            $extracted = TextExtractor::extract(Storage::disk('local')->path($path), $mime, $name);
            $document->update(['text' => $extracted['text'], 'text_status' => $extracted['status']]);
        }

        // What arrives by itself (a Gmail search, the mailbox) is received by the system; what you upload or send in
        // Telegram is yours.
        AuditLog::record('document.received', $document, null, ['name' => $name, 'source' => $source, 'size' => strlen($contents)], null,
            in_array($source, self::ARRIVES_BY_ITSELF, true) ? 'system' : null);

        // 'read' => false: the caller reads it itself (the phone's receipts are read on their own queue: Quick\QuickEntries).
        if (($meta['held'] ?? false) === false && ($meta['read'] ?? true) !== false && ! self::readByCaller($source, $parentId)) {
            if ($isEmail) {
                $this->readOrSpot($document->fresh());
            } elseif (Setting::get('ai_extraction') === 'on' && AiConfig::ready()) {
                $document->update(['status' => 'queued']);
                ReadDocument::dispatch($document->id);
            }
        }

        return $document->fresh();
    }

    /**
     * After an email is kept, look for work it may offer. With AI reading on, its body is read when it may be about
     * work (OpportunityRules::worthReading: it asks about work, its subject suggests it, it confirms or calls off work,
     * or it's a reply in a conversation that already has an opportunity). With $readLong (Gmail's searches, which pick
     * job emails), any email with enough to say is read, as before; so is one you forwarded yourself with nothing else
     * in it to read. Anything else (a hotel booking a filter forwarded, say) isn't sent to the AI. When the AI won't
     * read it, the rules look for an obvious offer of work by its words.
     *
     * @return 'read'|'spotted'|null
     */
    public function readOrSpot(Document $email, bool $readLong = false): ?string
    {
        if ($email->kind !== 'email' || $email->status !== 'new') {
            return null; // held for your approval, or already read
        }
        $long = mb_strlen((string) $email->text) > 300;
        if (Setting::get('ai_extraction') === 'on' && AiConfig::ready() && (OpportunityRules::worthReading($email)
            || ($long && ($readLong || (self::fromYou($email) && ! $email->children()->where('kind', '!=', 'email')->exists()))))) {
            $email->update(['status' => 'queued']);
            ReadDocument::dispatch($email->id);

            return 'read';
        }
        try {
            return app(OpportunityRules::class)->spot($email) ? 'spotted' : null;
        } catch (Throwable $e) {
            report($e); // a problem spotting work never stops an email being kept

            return null;
        }
    }

    /** Sent (or forwarded) by you, from one of your own addresses. */
    private static function fromYou(Document $email): bool
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $email->email_from, $m) === 1
            && isset(Opportunities::ownAddresses()[strtolower($m[0])]);
    }

    /**
     * Whoever passed this in reads it themselves: Gmail's scanner, once it has logged the message (so its thread is
     * known), and Telegram, which reads what you send it and replies with what it found (reading it here too would
     * read it twice).
     */
    private static function readByCaller(string $source, ?int $parentId): bool
    {
        if ($parentId !== null) {
            return false;
        }

        return $source === 'gmail' || ($source === 'telegram' && Setting::get('ai_extraction') !== 'off' && AiConfig::ready());
    }

    private function unpackEmail(Document $document, string $raw): void
    {
        $message = (new MailMimeParser)->parse($raw, false);

        $document->update([
            'email_from' => $document->email_from ?? (($from = $message->getHeaderValue('From')) ? mb_substr($from, 0, 250) : null),
            'email_subject' => $document->email_subject ?? mb_substr((string) $message->getHeaderValue('Subject'), 0, 500),
            'text' => self::emailText($raw),
            'text_status' => 'extracted',
        ]);

        foreach ($message->getAllAttachmentParts() as $part) {
            $name = $part->getFilename() ?: 'attachment';
            $content = $part->getContent();
            if ($content === null || $content === '' || str_starts_with((string) $part->getContentType(), 'image/') && strlen($content) < 15_000) {
                continue; // skip empty parts and small inline images such as logos and signatures
            }
            $this->store($content, $name, $part->getContentType(), $document->source, [
                'from' => $document->email_from, 'subject' => $document->email_subject, 'received_at' => $document->received_at,
                'work_job_id' => $document->work_job_id, 'job_linked_by' => $document->job_linked_by, 'held' => $document->status === 'held', 'held_reason' => $document->error,
            ], $document->id);
        }
    }

    /** The headers and body text of an email, as stored for checking quotes against. */
    public static function emailText(string $raw): string
    {
        $message = (new MailMimeParser)->parse($raw, false);
        $body = $message->getTextContent() ?? self::htmlText((string) $message->getHtmlContent());
        $header = 'From: '.$message->getHeaderValue('From')."\nTo: ".$message->getHeaderValue('To')."\nDate: ".$message->getHeaderValue('Date')."\nSubject: ".$message->getHeaderValue('Subject');

        return trim($header."\n\n".preg_replace("/\n{3,}/", "\n\n", (string) $body));
    }

    /**
     * An HTML-only email's text, with a line break where each block ended (a paragraph, a line break, a quoted
     * reply), so a quoted email's "From: … Sent: …" header stays on lines of its own (and is recognised as one), and
     * entities read as the characters they stand for.
     */
    public static function htmlText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|blockquote|tr|li|h[1-6]|table|ul|ol)\s*>|<(hr)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace("/[ \t]*\n[ \t]*/", "\n", $text) ?? $text);
    }

    public static function guessMime(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'eml' => 'message/rfc822',
            'txt', 'md' => 'text/plain',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
