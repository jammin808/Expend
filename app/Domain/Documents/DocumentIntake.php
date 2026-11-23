<?php

namespace App\Domain\Documents;

use App\Domain\Audit\AuditLog;
use App\Jobs\ReadDocument;
use App\Models\Document;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * The single way documents enter the app, whether uploaded, emailed or sent by Telegram.
 * Stores the original, extracts text, unpacks email attachments, ignores exact duplicates,
 * and queues the AI read when AI reading is switched on.
 */
final class DocumentIntake
{
    public const MAX_BYTES = 30 * 1024 * 1024;

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
        Storage::disk('local')->put($path, $contents);

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
            'kind' => $isEmail ? 'email' : 'unknown',
            'status' => ($meta['held'] ?? false) ? 'held' : 'new',
            'error' => $meta['held_reason'] ?? null,
            'work_job_id' => $meta['work_job_id'] ?? null,
        ]);

        if ($isEmail) {
            $this->unpackEmail($document, $contents);
        } else {
            $extracted = TextExtractor::extract(Storage::disk('local')->path($path), $mime, $name);
            $document->update(['text' => $extracted['text'], 'text_status' => $extracted['status']]);
        }

        AuditLog::record('document.received', $document, null, ['name' => $name, 'source' => $source, 'size' => strlen($contents)]);

        if (($meta['held'] ?? false) === false && Setting::get('ai_extraction') === 'on' && AiConfig::ready() && $document->kind !== 'email') {
            $document->update(['status' => 'queued']);
            ReadDocument::dispatch($document->id);
        }

        return $document->fresh();
    }

    private function unpackEmail(Document $document, string $raw): void
    {
        $message = (new MailMimeParser)->parse($raw, false);
        $body = $message->getTextContent() ?? strip_tags((string) $message->getHtmlContent());
        $header = 'From: '.$message->getHeaderValue('From')."\nTo: ".$message->getHeaderValue('To')."\nDate: ".$message->getHeaderValue('Date')."\nSubject: ".$message->getHeaderValue('Subject');

        $document->update([
            'email_from' => $document->email_from ?? (($from = $message->getHeaderValue('From')) ? mb_substr($from, 0, 250) : null),
            'email_subject' => $document->email_subject ?? mb_substr((string) $message->getHeaderValue('Subject'), 0, 500),
            'text' => trim($header."\n\n".preg_replace("/\n{3,}/", "\n\n", (string) $body)),
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
                'work_job_id' => $document->work_job_id, 'held' => $document->status === 'held', 'held_reason' => $document->error,
            ], $document->id);
        }
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
