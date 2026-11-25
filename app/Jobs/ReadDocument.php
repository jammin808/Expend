<?php

namespace App\Jobs;

use App\Domain\Ai\AiUsage;
use App\Domain\Ai\DocumentReader;
use App\Domain\Jobs\Opportunities;
use App\Domain\Quick\QuickReadCheck;
use App\Domain\Telegram\Telegram;
use App\Domain\Verification\Verifier;
use App\Models\Document;
use App\Models\QuickEntry;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a document with AI. Its calls are made for reading (AiUsage purposes: reading's share of the month's cap, once
 * the nightly cycle is on).
 *
 * Once the cycle is on, a read refused for want of AI budget (the month's cap, or reading's share of it) isn't a
 * failure: the document is left waiting_budget, and the nightly cycle (acquire.retry_reads) reads it again once there's
 * room. While the cycle is off or on trial nothing reads it again on its own, so it's failed, as before, where Read with
 * AI and the to-do list offer it again.
 */
class ReadDocument implements ShouldQueue
{
    use Queueable;

    /** A document whose read waits for AI budget. */
    public const WAITING = 'waiting_budget';

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $documentId, public ?string $telegramChatId = null) {}

    public function handle(DocumentReader $reader, Verifier $verifier): void
    {
        $document = Document::query()->find($this->documentId);
        if (! $document || $document->status === 'held') {
            return;
        }

        AiUsage::withPurpose('reading', function () use (&$document, $reader, $verifier) {
            try {
                $document->update(['status' => 'queued']);
                $document = $reader->read($document);
                // A photo from the phone is checked against what you typed (QuickReadCheck), not read a second time.
                $quick = QuickEntry::query()->where('document_id', $document->id)->exists();
                try {
                    $verifier->document($document, ! $quick && $document->text_status !== 'extracted' && Setting::get('ai_extraction') === 'on');
                } catch (Throwable $e) {
                    report($e);
                }
                if ($quick) {
                    try {
                        app(QuickReadCheck::class)->afterRead($document);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                // Work it may offer (an availability check, a hold, an offer): proposed, never made a job by itself.
                try {
                    app(Opportunities::class)->fromDocument($document);
                } catch (Throwable $e) {
                    report($e);
                }
                FindJobs::dispatch();
            } catch (Throwable $e) {
                // Out of AI budget, with the cycle on: it waits and is read again when there's room. Anything else failed.
                $document->update(['status' => AiUsage::refused($e) && AiUsage::sharesApply() ? self::WAITING : 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                report($e);
            }
        });

        if ($this->telegramChatId) {
            // The summary, and for a receipt the offer to add it (Telegram::readReply).
            Telegram::readReply($document->fresh(['facts', 'job']), $this->telegramChatId);
        }
    }
}
